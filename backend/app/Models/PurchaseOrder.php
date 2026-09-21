<?php

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrder extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'branch_id',
        'warehouse_id',
        'supplier_id',
        'purchase_request_id',
        'approved_by',
        'number',
        'order_date',
        'expected_date',
        'payment_terms',
        'currency',
        'status',
        'subtotal',
        'item_discount_total',
        'discount_total',
        'tax_total',
        'shipping_cost',
        'other_charges',
        'grand_total',
        'approved_at',
        'sent_at',
        'closed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'expected_date' => 'date',
            'status' => PurchaseOrderStatus::class,
            'subtotal' => 'decimal:4',
            'item_discount_total' => 'decimal:4',
            'discount_total' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'shipping_cost' => 'decimal:4',
            'other_charges' => 'decimal:4',
            'grand_total' => 'decimal:4',
            'approved_at' => 'datetime',
            'sent_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
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

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class, 'purchase_order_id');
    }

    public function purchaseReturns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class, 'purchase_order_id');
    }

    /**
     * Whether goods may still be received against this order.
     *
     * A cancelled or closed order is shut to receipts (spec §49), so the
     * goods-receipt flow must gate on this before writing any line.
     */
    public function canReceive(): bool
    {
        return ! in_array($this->status, [
            PurchaseOrderStatus::Cancelled,
            PurchaseOrderStatus::Closed,
        ], true);
    }

    /**
     * Recompute the received progress from the line-level quantity_received
     * values and advance the status accordingly.
     *
     * Called by the goods-receipt flow after a receipt is posted. Nothing is
     * written for an order that is closed to receipts, and a receipt that
     * leaves nothing behind leaves the status untouched.
     */
    public function markReceivedProgress(): void
    {
        if (! $this->canReceive()) {
            return;
        }

        $this->load('items');

        $ordered = '0';
        $received = '0';

        foreach ($this->items as $item) {
            $ordered = bcadd($ordered, (string) $item->quantity, 6);
            $received = bcadd($received, (string) $item->quantity_received, 6);
        }

        if (bccomp($received, '0', 6) <= 0) {
            return;
        }

        $this->forceFill([
            'status' => bccomp($received, $ordered, 6) >= 0
                ? PurchaseOrderStatus::Received
                : PurchaseOrderStatus::PartiallyReceived,
        ])->save();
    }
}
