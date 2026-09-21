<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseTransferItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'warehouse_transfer_id',
        'product_id',
        'product_variant_id',
        'unit_id',
        'quantity',
        'quantity_received',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'quantity_received' => 'decimal:6',
        ];
    }

    public function warehouseTransfer(): BelongsTo
    {
        return $this->belongsTo(WarehouseTransfer::class);
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

    /**
     * Units still unreceived for this line, as an exact bcmath string.
     *
     * Partial receiving means this only reaches zero once every unit lands, so
     * the transfer's completion test is "no line has anything outstanding".
     */
    public function quantityOutstanding(): string
    {
        return bcsub($this->quantity, $this->quantity_received, 6);
    }
}
