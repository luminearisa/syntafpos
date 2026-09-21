<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockBalance extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id', 'branch_id', 'warehouse_id', 'location_id',
        'product_id', 'product_variant_id', 'unit_id',
        'on_hand', 'reserved', 'incoming', 'outgoing',
        'average_cost', 'last_cost', 'last_movement_at',
    ];

    protected function casts(): array
    {
        return [
            'on_hand' => 'decimal:6',
            'reserved' => 'decimal:6',
            'incoming' => 'decimal:6',
            'outgoing' => 'decimal:6',
            'average_cost' => 'decimal:4',
            'last_cost' => 'decimal:4',
            'last_movement_at' => 'datetime',
        ];
    }

    /**
     * Quantity free to promise: on hand less anything already spoken for.
     */
    public function available(): string
    {
        return bcsub($this->on_hand, $this->reserved, 6);
    }

    /**
     * Valuation of what is physically present at this location.
     */
    public function onHandValue(): string
    {
        return bcmul($this->on_hand, $this->average_cost, 4);
    }

    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('company_id', $user->companies()->select('companies.id'));
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'location_id');
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
}
