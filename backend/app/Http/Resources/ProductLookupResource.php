<?php

namespace App\Http\Resources;

use App\Models\Product;
use App\Support\DecimalMath;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The point-of-sale answer for one scanned code: what was matched, at what
 * price, in which unit, and how much is actually available to promise.
 */
class ProductLookupResource extends JsonResource
{
    public function __construct(Product $product, private readonly ?int $variantId = null)
    {
        parent::__construct($product);
    }

    public function toArray(Request $request): array
    {
        /** @var Product $product */
        $product = $this->resource;

        $variant = $this->variantId !== null
            ? $product->variants->firstWhere('id', $this->variantId)
            : null;

        $onHand = bcadd('0', '0', 6);
        $reserved = bcadd('0', '0', 6);

        foreach ($this->matchingBalances() as $balance) {
            $onHand = DecimalMath::add($onHand, (string) $balance->on_hand, 6);
            $reserved = DecimalMath::add($reserved, (string) $balance->reserved, 6);
        }

        $price = $variant?->selling_price ?? $product->selling_price;

        return [
            'product' => new ProductResource($product),
            'variant_id' => $variant?->id,
            'variant' => $variant !== null ? new ProductVariantResource($variant) : null,
            'unit_id' => $product->default_unit_id,
            'unit' => $product->defaultUnit !== null ? [
                'id' => $product->defaultUnit->id,
                'name' => $product->defaultUnit->name,
                'code' => $product->defaultUnit->code,
            ] : null,
            'price' => (string) $price,
            'stock' => [
                'on_hand' => $onHand,
                'reserved' => $reserved,
                'available' => DecimalMath::sub($onHand, $reserved, 6),
            ],
        ];
    }

    /**
     * Balances that belong to the matched line: the variant's only, or the
     * product's whole ledger when no variant was matched.
     */
    private function matchingBalances()
    {
        return $this->variantId !== null
            ? $this->resource->stockBalances->where('product_variant_id', $this->variantId)
            : $this->resource->stockBalances;
    }
}
