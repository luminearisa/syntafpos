<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\SaleReturnStatus;
use App\Enums\SaleStatus;
use App\Support\DecimalMath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A completed point-of-sale transaction: the document a cart becomes.
 *
 * Two properties define this table. It is numbered by the Phase 1 sequence
 * engine (INV-2026-000001), which makes it citable on an invoice and on a stock
 * movement's reference columns. And it is a snapshot: product name, SKU, price,
 * tax, discount and unit live on the lines, customer and outlet details live
 * here, so renaming a product or moving a customer later cannot rewrite what
 * already happened at the counter.
 *
 * Money columns are derived by PosCartCalculationService, which serves carts and
 * sales alike — a client-sent total is never stored, exactly as on the cart.
 */
class Sale extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id', 'branch_id', 'warehouse_id', 'register_id', 'customer_id',
        'cashier_id', 'pos_cart_id', 'number', 'date', 'notes', 'currency',
        'discount_input', 'discount_type', 'other_charges',
        'customer_name', 'customer_code', 'customer_phone', 'customer_email', 'customer_address',
        'branch_name', 'branch_address', 'branch_phone', 'register_code',
    ];

    /**
     * Status and every derived figure are written by the service that owns them,
     * so they stay out of fillable and are set with forceFill instead.
     */
    protected $attributes = [
        'status' => 'draft',
        'currency' => 'IDR',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'status' => SaleStatus::class,
            'stock_posted_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'subtotal' => 'decimal:4',
            'item_discount_total' => 'decimal:4',
            'discount_input' => 'decimal:4',
            'discount_type' => DiscountType::class,
            'discount_total' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'tax_included_total' => 'decimal:4',
            'other_charges' => 'decimal:4',
            'rounding' => 'decimal:4',
            'grand_total' => 'decimal:4',
            'paid_total' => 'decimal:4',
            'change_due' => 'decimal:4',
        ];
    }

    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('company_id', $user->companies()->select('companies.id'));
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class);
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

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(PosCart::class, 'pos_cart_id');
    }

    /**
     * The amount still owed. Derived from the stored columns rather than
     * recomputed from the payment rows, so the figure an invoice prints is the
     * same one the status machine used.
     */
    public function balanceDue(): string
    {
        return bcsub((string) $this->grand_total, (string) $this->paid_total, 4);
    }

    /**
     * Whether this sale's goods are currently out of the shop.
     *
     * The timestamp records that stock was posted, and a cancellation is what
     * puts it back; reading the pair rather than the status means a rollback
     * that never reached the ledger cannot be mistaken for a movement, and a
     * cancelled sale cannot be reversed twice.
     */
    public function stockPosted(): bool
    {
        return $this->stock_posted_at !== null && $this->status !== SaleStatus::Cancelled;
    }

    /**
     * What can still be refunded on this sale: the tenders that settled, net of
     * everything already given back.
     *
     * Read off the payment rows rather than off `paid_total` so the two agree by
     * construction — `paid_total` is itself summed through the same net figures.
     * A fully refunded tender contributes nothing, which is what stops a second
     * refund from overdrawing a payment the first one emptied.
     */
    public function refundableAmount(): string
    {
        $payments = $this->relationLoaded('payments')
            ? $this->payments
            : $this->payments()->get();

        $total = '0';

        foreach ($payments as $payment) {
            $total = DecimalMath::add($total, $payment->netAmount());
        }

        return $total;
    }

    /**
     * Everything already refunded against this sale's tenders.
     */
    public function refundedTotal(): string
    {
        $payments = $this->relationLoaded('payments')
            ? $this->payments
            : $this->payments()->get();

        $total = '0';

        foreach ($payments as $payment) {
            $total = DecimalMath::add($total, (string) $payment->refunded_amount);
        }

        return $total;
    }

    /**
     * The value of goods returned so far, across posted returns only. A draft
     * return has not moved anything and must not be counted.
     */
    public function returnedTotal(): string
    {
        return (string) $this->returns()
            ->where('status', SaleReturnStatus::Completed->value)
            ->sum('grand_total');
    }
}
