<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductVariant;
use App\Support\BusinessContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Resolves the price a till should charge for one product line.
 *
 * Phase 2 stored the pricing model but never read it back: the catalogue kept
 * price lists, per-product prices and a customer's own list, while every lookup
 * returned the raw products.selling_price. This is the read side of that engine.
 *
 * Resolution order, most specific first:
 *   1. an explicit price list for the customer;
 *   2. a price list for the customer's group;
 *   3. a price list scoped to the active branch;
 *   4. the company's default price list;
 *   5. the variant's own selling price;
 *   6. the product's selling price.
 *
 * Inside a price list a variant-specific row beats a product-level one. A price
 * list only counts when it is active and its validity window covers today, so an
 * expired promotion cannot silently bill a customer.
 */
class PriceResolutionService
{
    /**
     * Where a resolved price came from, reported to the till so the cashier can
     * see which tier a customer is being billed at.
     */
    public const SOURCE_PRICE_LIST = 'price_list';

    public const SOURCE_CUSTOMER_GROUP = 'customer_group';

    public const SOURCE_BRANCH = 'branch';

    public const SOURCE_DEFAULT_LIST = 'default_list';

    public const SOURCE_VARIANT = 'variant';

    public const SOURCE_PRODUCT = 'product';

    public function __construct(private BusinessContext $context) {}

    /**
     * Resolve a sellable unit price and the list it came from.
     *
     * @return array{price: string, source: string, price_list_id: ?int}
     */
    public function resolve(Product $product, ?int $variantId = null, ?int $customerId = null): array
    {
        $list = $this->resolveList($product->company_id, $customerId);

        if ($list !== null) {
            $priced = $this->priceFromList($list, $product->id, $variantId);

            if ($priced !== null) {
                return [
                    'price' => $priced['price'],
                    'source' => $priced['source'],
                    'price_list_id' => $list->id,
                ];
            }
        }

        // No list covered this line, so fall back to the catalogue prices.
        if ($variantId !== null) {
            $variant = $product->variants->firstWhere('id', $variantId)
                ?? ProductVariant::query()->find($variantId);

            if ($variant && bccomp((string) $variant->selling_price, '0', 4) > 0) {
                return ['price' => (string) $variant->selling_price, 'source' => self::SOURCE_VARIANT, 'price_list_id' => null];
            }
        }

        return ['price' => (string) $product->selling_price, 'source' => self::SOURCE_PRODUCT, 'price_list_id' => null];
    }

    /**
     * The price list that should drive this line, or null when none applies.
     */
    public function resolveList(int $companyId, ?int $customerId): ?PriceList
    {
        $today = Carbon::today()->toDateString();

        $usable = fn (PriceList $list): bool => $list->status === 'active'
            && ($list->start_date === null || $list->start_date->toDateString() <= $today)
            && ($list->end_date === null || $list->end_date->toDateString() >= $today);

        if ($customerId !== null) {
            $customer = Customer::query()->where('company_id', $companyId)->find($customerId);

            if ($customer !== null) {
                // A customer's own list wins outright, then their group's.
                $own = $customer->price_list_id
                    ? $this->findList($companyId, ['id' => $customer->price_list_id])
                    : null;

                if ($own && $usable($own)) {
                    return $own;
                }

                $group = $customer->customer_group_id
                    ? $this->findList($companyId, ['customer_group_id' => $customer->customer_group_id])
                    : null;

                if ($group && $usable($group)) {
                    return $group;
                }
            }
        }

        if ($branchId = $this->context->branchId()) {
            $branchList = $this->findList($companyId, ['branch_id' => $branchId]);

            if ($branchList && $usable($branchList)) {
                return $branchList;
            }
        }

        $default = $this->findList($companyId, ['is_default' => true]);

        return $default && $usable($default) ? $default : null;
    }

    /**
     * Read one price off a list: the variant row if it exists, else the product row.
     *
     * @return array{price: string, source: string}|null
     */
    private function priceFromList(PriceList $list, int $productId, ?int $variantId): ?array
    {
        $rows = ProductPrice::query()
            ->where('price_list_id', $list->id)
            ->where('product_id', $productId)
            ->where(fn ($q) => $q->where('product_variant_id', $variantId)->orWhereNull('product_variant_id'))
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        // A variant-specific row is more specific than the product-level one,
        // and a zero price is treated as "not priced here" rather than "free".
        $match = ($variantId !== null ? $rows->firstWhere('product_variant_id', $variantId) : null)
            ?? $rows->firstWhere('product_variant_id', null);

        if ($match === null || bccomp((string) $match->price, '0', 4) <= 0) {
            return null;
        }

        $source = $list->customer_group_id !== null
            ? self::SOURCE_CUSTOMER_GROUP
            : ($list->branch_id !== null ? self::SOURCE_BRANCH : ($list->is_default ? self::SOURCE_DEFAULT_LIST : self::SOURCE_PRICE_LIST));

        return ['price' => (string) $match->price, 'source' => $source];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function findList(int $companyId, array $attributes): ?PriceList
    {
        return PriceList::query()
            ->where('company_id', $companyId)
            ->where($attributes)
            ->latest('id')
            ->first();
    }

    /**
     * Resolve the same price for a whole page of search hits.
     *
     * A list is selected once per cart context — the customer does not change
     * mid-search — and its rows are read in one query, so the grid prices N
     * products in two queries regardless of page size.
     *
     * @param  Collection<int, Product>  $products
     * @return array<int, array{price: string, source: string, price_list_id: ?int}> keyed by product id
     */
    public function resolveMany(Collection $products, ?int $customerId = null): array
    {
        if ($products->isEmpty()) {
            return [];
        }

        // The search grid prices product lines, not a single chosen variant, so
        // only product-level rows are considered here.
        $list = $this->resolveList($products->first()->company_id, $customerId);

        $rows = $list === null
            ? collect()
            : ProductPrice::query()
                ->where('price_list_id', $list->id)
                ->whereIn('product_id', $products->pluck('id'))
                ->whereNull('product_variant_id')
                ->get()
                ->keyBy('product_id');

        $source = $this->listSource($list);
        $resolved = [];

        foreach ($products as $product) {
            $priced = $rows->get($product->id);

            if ($priced !== null && bccomp((string) $priced->price, '0', 4) > 0) {
                $resolved[$product->id] = [
                    'price' => (string) $priced->price,
                    'source' => $source,
                    'price_list_id' => $list->id,
                ];

                continue;
            }

            $resolved[$product->id] = [
                'price' => (string) $product->selling_price,
                'source' => self::SOURCE_PRODUCT,
                'price_list_id' => null,
            ];
        }

        return $resolved;
    }

    private function listSource(?PriceList $list): string
    {
        if ($list === null) {
            return self::SOURCE_PRODUCT;
        }

        return $list->customer_group_id !== null
            ? self::SOURCE_CUSTOMER_GROUP
            : ($list->branch_id !== null ? self::SOURCE_BRANCH : ($list->is_default ? self::SOURCE_DEFAULT_LIST : self::SOURCE_PRICE_LIST));
    }
}
