<?php

namespace App\Models;

use App\Enums\RefundMethod;
use App\Enums\RefundStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Money going back to a customer against a sale they already paid for.
 *
 * A refund is the most gated document in the sales family: it is raised against
 * a sale, may need a manager's signature, and only writes the tenders down when
 * it completes. Its `allocations` are the whole point — a refund says not just
 * *how much* but *which payments* it came off, so a sale paid half in notes and
 * half by card refunds each in the right proportion and no tender is written down
 * twice.
 *
 * The status machine lives in RefundStatus, and the approval threshold is
 * snapshotted onto the row: editing the shop's rule tomorrow must not rewrite the
 * fact that this refund was, at the time, judged against the old one.
 */
class Refund extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id', 'sale_id', 'sale_return_id', 'register_id',
        'register_session_id', 'number', 'method', 'status', 'amount', 'currency',
        'reason', 'external_reference', 'approval_threshold', 'approval_required',
        'requested_by', 'requested_at', 'approved_by', 'approved_at',
        'rejected_by', 'rejected_at', 'rejection_reason', 'processed_by',
        'processed_at', 'failure_reason', 'metadata', 'notes',
    ];

    protected $attributes = [
        'status' => 'requested',
        'currency' => 'IDR',
    ];

    protected function casts(): array
    {
        return [
            'method' => RefundMethod::class,
            'status' => RefundStatus::class,
            'amount' => 'decimal:4',
            'approval_threshold' => 'decimal:4',
            'approval_required' => 'boolean',
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'processed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('company_id', $user->companies()->select('companies.id'));
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(RefundAllocation::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(Register::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /**
     * Whether the money has actually left the shop, and therefore whether the
     * allocations have been written down against the payments.
     */
    public function isSettled(): bool
    {
        return $this->status?->isSettled() ?? false;
    }
}
