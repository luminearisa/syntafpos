<?php

namespace App\Models;

use App\Enums\DiscountType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a sales return, frozen the moment the goods come back.
 *
 * The catalogue links are nullable for the same reason a sale line's are: the
 * return must outlive any renaming or removal of the product behind it. What is
 * stored is what the original sale line held, scaled to the quantity returned —
 * unit price, discount, tax rate and mode — plus the cost basis the goods left
 * at, which is what Phase 4 reverses COGS from.
 */
class SaleReturnItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_return_id', 'sale_item_id', 'company_id', 'product_id',
        'product_variant_id', 'unit_id', 'tax_id', 'product_name', 'product_sku',
        'variant_name', 'unit_code', 'quantity', 'unit_price', 'discount',
        'discount_type', 'discount_amount', 'tax_rate', 'tax_mode', 'tax_amount',
        'line_subtotal', 'line_total', 'unit_cost', 'total_cost', 'restock',
        'reason', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'unit_price' => 'decimal:4',
            'discount' => 'decimal:4',
            'discount_type' => DiscountType::class,
            'discount_amount' => 'decimal:4',
            'tax_rate' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'line_subtotal' => 'decimal:4',
            'line_total' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'total_cost' => 'decimal:4',
            'restock' => 'boolean',
        ];
    }

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }
}
