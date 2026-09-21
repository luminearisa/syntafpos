<?php

namespace App\Models;

use App\Enums\DiscountType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a sale, frozen at checkout.
 *
 * The catalogue links are nullable and nullOnDelete on purpose: a transaction
 * line that disappears when a product is edited away is not a record any more.
 * Everything the receipt and the invoice need is stored here — name, SKU,
 * barcode, unit, price, discount, tax rate and its mode — so no read of a
 * historical sale has to join back to the catalogue.
 */
class SaleItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_id', 'company_id', 'product_id', 'product_variant_id', 'unit_id', 'tax_id',
        'product_name', 'product_sku', 'barcode', 'variant_name', 'unit_code',
        'quantity', 'unit_price', 'price_source', 'discount', 'discount_type',
        'tax_rate', 'tax_mode', 'notes',
    ];

    /**
     * line_subtotal, discount_amount, tax_amount and line_total are computed by
     * the calculation service and written with forceFill, as on a cart line.
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

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
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
