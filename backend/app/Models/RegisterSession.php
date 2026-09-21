<?php

namespace App\Models;

use App\Enums\RegisterSessionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One shift: a register opened with float, worked, and closed with a count.
 *
 * The row is the drawer's account of itself. Everything on it is either typed once
 * and never derived (opening balance, the actual count, the reasons) or computed by
 * `RegisterSessionService` from records that belong to other tables (the expected
 * figure, the variance). Nothing here is a running total another process updates —
 * which is why a shift cannot drift out of step with its sales: the sales are read
 * when the shift is reported on, not accumulated as they happen.
 *
 * `cashier_id` is who the shift is *on*; `opened_by`, `closed_by`, `approved_by` and
 * `reopened_by` are who did each action. They usually agree and matter exactly when
 * they do not — a supervisor who opens a drawer for a new cashier, a manager who
 * closes and counts while the cashier is on a break. Keeping the two apart is what
 * lets a variance conversation be about the money rather than about who pressed the
 * button.
 *
 * `register_open_key` is the constraint behind "one open shift per register": the
 * register's identity while this is the open session, NULL once closed. See the
 * migration for why that shape, rather than a check before insert.
 */
class RegisterSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id', 'branch_id', 'register_id', 'warehouse_id', 'cashier_id',
        'number', 'status', 'register_open_key',
        'opening_balance', 'opened_at', 'opened_by',
        'closed_at', 'closed_by', 'closing_balance', 'actual_balance', 'variance',
        'variance_threshold', 'requires_approval', 'is_approved', 'approved_by',
        'approved_at', 'approval_note', 'notes',
    ];

    protected $attributes = [
        'status' => 'open',
        'opening_balance' => '0.0000',
        'requires_approval' => false,
        'is_approved' => false,
        'reopen_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => RegisterSessionStatus::class,
            'opening_balance' => 'decimal:4',
            'closing_balance' => 'decimal:4',
            'actual_balance' => 'decimal:4',
            'variance' => 'decimal:4',
            'variance_threshold' => 'decimal:4',
            'requires_approval' => 'boolean',
            'is_approved' => 'boolean',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'approved_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }

    /**
     * Restrict to shifts reachable by the given user.
     *
     * Company-scoped rather than register-scoped, the same way Sale is: the person
     * closing a shift needs to read every document inside it, and a till's own
     * register is already pinned by the business context. Narrowing this to the
     * registers a user is attached to would hide a shift's takings from the shop
     * that owns them, while a cashier still cannot read another company's rows
     * either way.
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

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    public function isOpen(): bool
    {
        return $this->status === RegisterSessionStatus::Open;
    }

    public function isClosed(): bool
    {
        return $this->status === RegisterSessionStatus::Closed;
    }

    /**
     * Whether the shift is shut but still waiting for a supervisor's signature.
     *
     * Derived instead of stored: the three facts behind it (closed, over threshold,
     * not approved) are already on the row, and a fourth column would be a way for
     * the row to disagree with itself.
     */
    public function isAwaitingApproval(): bool
    {
        return $this->isClosed() && $this->requires_approval && ! $this->is_approved;
    }

    /**
     * The register identity this session occupies while it is open.
     */
    public static function openKeyFor(int $registerId): string
    {
        return 'register:'.$registerId;
    }

    /**
     * How long the drawer was worked, for the closing report.
     *
     * An open shift counts against now, which is what a manager watching the floor
     * wants to see; a closed one counts to its close.
     */
    public function durationMinutes(?Carbon $now = null): int
    {
        return (int) $this->opened_at->diffInMinutes($this->closed_at ?? $now ?? Carbon::now(), true);
    }
}
