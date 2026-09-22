<?php

namespace App\Models;

use App\Enums\SaleReturnStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Goods coming back from a customer, against the sale they came from.
 *
 * A return is the counter's reversal of a sale: it names the original document,
 * carries its own numbered slip, and puts stock back through the Phase 2 ledger
 * in the same transaction that writes it. Nothing here edits the sale; the sale
 * stays as it was and the return is the second half of its story, which is what
 * lets Phase 4 reverse revenue, tax, COGS and inventory from a pair of documents
 * rather than from a mutation somebody has to reconstruct.
 *
 * The line is a full snapshot — price, discount, tax mode and the cost basis the
 * goods left at — so what came back is derivable years later without reading the
 * catalogue as it stands today, exactly as a sale line is.
 */
class SaleReturn extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id', 'branch_id', 'warehouse_id', 'sale_id', 'register_id',
        'register_session_id', 'customer_id', 'returned_by', 'number',
        'return_date', 'status', 'reason', 'posted_at', 'subtotal',
        'discount_total', 'tax_total', 'grand_total', 'cost_total', 'currency',
        'notes',
    ];

    protected $attributes = [
        'status' => 'draft',
        'currency' => 'IDR',
    ];

    protected function casts(): array
    {
        return [
            'return_date' => 'date',
            'status' => SaleReturnStatus::class,
            'posted_at' => 'datetime',
            'subtotal' => 'decimal:4',
            'discount_total' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'grand_total' => 'decimal:4',
            'cost_total' => 'decimal:4',
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

    public function items(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
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

    public function register(): BelongsTo
    {
        return $this->belongsTo(Register::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function returnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    /**
     * Whether this return has already put its stock back and counts towards the
     * quantities a sale may still return.
     */
    public function isPosted(): bool
    {
        return $this->status === SaleReturnStatus::Completed;
    }
}
