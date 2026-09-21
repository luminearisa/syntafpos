<?php

namespace App\Models;

use App\Enums\DiscountType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosCartItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'pos_cart_id', 'company_id', 'product_id', 'product_variant_id', 'unit_id', 'tax_id',
        'product_name', 'product_sku', 'barcode', 'variant_name', 'unit_code',
        'quantity', 'unit_price', 'price_source', 'discount', 'discount_type',
        'tax_rate', 'tax_mode', 'notes',
    ];

    /**
     * line_subtotal, discount_amount, tax_amount and line_total are computed;
     * forceFill is used by the service that owns them.
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'unit_price' => 'decimal:4',
            'discount' => 'decimal:4',
            'discount_type' => DiscountType::class,
            'tax_rate' => 'decimal:4',
            'line_subtotal' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'line_total' => 'decimal:4',
        ];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(PosCart::class, 'pos_cart_id');
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
