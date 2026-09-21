<?php

namespace App\Models;

use App\Enums\CashMovementType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money that moved through a drawer without being a tender.
 *
 * Float handed over, a supplier paid in cash, a withdrawal for bank drop, petty
 * cash given to the kitchen, a refund paid back across the counter. Without these
 * a shift cannot reconcile — the drawer would be short by exactly the amount of
 * every unrecorded action a cashier took — and with them it can be, to the rupiah.
 *
 * The row is deliberately append-only. The engine writes movements and never
 * revises them, so a shift's expected figure is stable once counted; correcting a
 * mistake means recording the opposite movement with a reason, which is what an
 * auditor can read, rather than editing a number that was already reported.
 *
 * `amount` is always positive and the direction comes from `type->sign()`. That is
 * the whole defence against a movement whose reason says "in" and whose sign says
 * "out": there is one place the sign lives, and it is not a field anyone can send.
 */
class CashMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id', 'branch_id', 'register_id', 'register_session_id', 'user_id',
        'type', 'amount', 'currency', 'reason', 'reference', 'occurred_at', 'notes',
    ];

    protected $attributes = [
        'currency' => 'IDR',
    ];

    protected function casts(): array
    {
        return [
            'type' => CashMovementType::class,
            'amount' => 'decimal:4',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * Restrict to movements reachable by the given user.
     *
     * Company-scoped, matching RegisterSession: a movement belongs to a shift, and
     * the two must not answer "can this person read it" differently — a shift whose
     * cash-in lines silently disappear from its own report is a reconciliation that
     * looks worse the more you check it.
     */
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

    public function register(): BelongsTo
    {
        return $this->belongsTo(Register::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(RegisterSession::class, 'register_session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The amount as it enters the expected-cash sum.
     */
    public function signedAmount(): string
    {
        return bcmul((string) $this->amount, (string) $this->type->sign(), 4);
    }
}
