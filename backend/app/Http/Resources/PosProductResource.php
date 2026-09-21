<?php

namespace App\Http\Resources;

use App\Models\Product;
use App\Models\StockBalance;
use App\Support\DecimalMath;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * One product as the till grid renders it: what it is, what it costs this
 * customer, and how much is on the shelf.
 *
 * Deliberately not ProductResource. The catalogue exposes costing, margins and
 * reorder policy, none of which belong on a checkout screen, and its price is
 * the raw catalogue figure — while a cashier must charge the tiered price the
 * price engine resolved for the customer on the cart.
 *
 * The resolved price and the stock figure are handed in rather than read off
 * the model, because both are computed once per page: the tier price from a
 * single price-list query, the stock from the balances eager loaded for the
 * till's warehouse only.
 */
class PosProductResource extends JsonResource
{
    /**
     * @param  array{price: string, source: string, price_list_id: ?int}  $price
     * @param  array{on_hand: string, available: string}  $stock
     */
    public function __construct(
        Product $product,
        private readonly array $price,
        private readonly array $stock,
        private readonly ?Collection $variantStock = null,
    ) {
        parent::__construct($product);
    }

    public function toArray(Request $request): array
    {
        /** @var Product $product */
        $product = $this->resource;

        return [
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'product_type' => $product->product_type?->value,
            'image' => $product->image,
            'track_inventory' => (bool) $product->track_inventory,
            'allow_negative_stock' => (bool) $product->allow_negative_stock,

            'unit' => $product->defaultUnit ? [
                'id' => $product->defaultUnit->id,
                'name' => $product->defaultUnit->name,
                'code' => $product->defaultUnit->code,
            ] : null,

            'category' => $product->category ? ['id' => $product->category->id, 'name' => $product->category->name] : null,
            'brand' => $product->brand ? ['id' => $product->brand->id, 'name' => $product->brand->name] : null,

            // Price engine output: the figure the till should charge, plus where
            // it came from so the cashier can explain a non-shelf price.
            'price' => $this->price['price'],
            'price_source' => $this->price['source'],
            'price_list_id' => $this->price['price_list_id'],
            'catalogue_price' => (string) $product->selling_price,

            // Read-only availability. Adding to a cart never reserves stock.
            'stock' => [
                'on_hand' => $this->stock['on_hand'],
                'available' => $this->stock['available'],
                'tracked' => (bool) $product->track_inventory,
                'low' => bccomp($this->stock['on_hand'], (string) $product->reorder_point, 6) <= 0,
            ],

            'variants' => $product->variants
                ->where('is_active', true)
                ->map(fn ($variant) => [
                    'id' => $variant->id,
                    'sku' => $variant->sku,
                    'barcode' => $variant->barcode,
                    'name' => $variant->name,
                    'selling_price' => (string) $variant->selling_price,
                    'stock' => $this->variantStock?->has($variant->id)
                        ? $this->variantStock->get($variant->id)
                        : ['on_hand' => '0', 'available' => '0'],
                ])
                ->values()
                ->all(),

            'minimum_selling_price' => $product->minimum_selling_price !== null
                ? (string) $product->minimum_selling_price
                : null,
        ];
    }

    /**
     * Totals of the balance rows eager loaded for this page.
     *
     * @param  Collection<int, StockBalance>  $balances
     * @return array{on_hand: string, available: string}
     */
    public static function stockOf(Collection $balances): array
    {
        $onHand = '0';
        $reserved = '0';

        foreach ($balances as $balance) {
            $onHand = DecimalMath::add($onHand, (string) $balance->on_hand, 6);
            $reserved = DecimalMath::add($reserved, (string) $balance->reserved, 6);
        }

        return ['on_hand' => $onHand, 'available' => DecimalMath::sub($onHand, $reserved, 6)];
    }
}
