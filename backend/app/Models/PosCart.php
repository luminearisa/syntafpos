<?php

namespace App\Models;

use App\Enums\CartStatus;
use App\Enums\DiscountType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A point-of-sale cart: a draft transaction held on the till.
 *
 * A cart is not a document. It never reserves stock, never moves the ledger and
 * never produces revenue, so nothing here is referenced by stock_movements.
 * Completing a sale in Subphase 3.2 reads this row and writes the real
 * transaction; the cart itself is then consumed.
 */
class PosCart extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id', 'branch_id', 'warehouse_id', 'register_id', 'user_id',
        'customer_id', 'number', 'status', 'label', 'held_at', 'notes', 'currency',
        'discount_input', 'discount_type', 'other_charges',
    ];

    /**
     * Money columns are derived, never assigned: PosCartCalculationService is
     * the only writer, mirroring how the purchasing documents behave.
     */
    protected $attributes = [
        'status' => 'active',
        'currency' => 'IDR',
    ];

    protected function casts(): array
    {
        return [
            'status' => CartStatus::class,
            'held_at' => 'datetime',
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
        ];
    }

    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('company_id', $user->companies()->select('companies.id'));
    }

    /**
     * The cart a cashier is currently working on at a register.
     */
    public function scopeWorking(Builder $query, int $companyId, int $userId, ?int $registerId): Builder
    {
        return $query->where('company_id', $companyId)
            ->where('user_id', $userId)
            ->where('status', CartStatus::Active)
            ->when(
                $registerId,
                fn (Builder $q) => $q->where('register_id', $registerId),
                fn (Builder $q) => $q->whereNull('register_id')
            );
    }

    public function items(): HasMany
    {
        return $this->hasMany(PosCartItem::class);
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

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * A cart may be worked on until it is handed to checkout.
     */
    public function isEditable(): bool
    {
        return $this->status === CartStatus::Active;
    }
}
