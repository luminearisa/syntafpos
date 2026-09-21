<?php

namespace App\Models;

use App\Contracts\Payments\PaymentMethodInterface;
use App\Enums\PaymentChannel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A way this shop takes money, as its owner configured it.
 *
 * This is the "payment method harus configurable" half of Phase 3.3: the name on
 * the till button, whether a reference must be typed, the order the methods are
 * listed in, and which provider (if any) captures the money. What is *not*
 * configurable is the behaviour that follows from what the money physically is —
 * only a drawer can hand change back — so that is read from `channel`, a closed
 * set, while everything a shop may legitimately choose is read from the row.
 *
 * The row is master data and is soft-deletable; payments reference it with
 * nullOnDelete and carry their own copy of the channel and name, so retiring a
 * method never rewrites a receipt. That copy is the same decision Phase 3.2 made
 * about products, for the same reason.
 */
class PaymentMethod extends Model implements PaymentMethodInterface
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'channel',
        'provider',
        'icon',
        'description',
        'requires_reference',
        'is_default',
        'is_active',
        'sort_order',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'channel' => PaymentChannel::class,
            'requires_reference' => 'boolean',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'settings' => 'array',
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
     * Methods this shop offers a cashier right now, in till order.
     *
     * Active only, and `other` is deliberately not excluded: a shop that sells a
     * voucher or accepts a government aid payment configures a method for it, and
     * a channel the catalogue calls "other" is that shop's answer, not a gap to
     * hide from its own counter.
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Tenders taken on this method.
     *
     * Kept so the delete endpoint can refuse a method that real money has passed
     * through: a shop should deactivate it instead, so the history keeps pointing
     * at something.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    public function channel(): PaymentChannel
    {
        return $this->channel;
    }

    public function displayName(): string
    {
        return $this->name;
    }

    public function requiresReference(): bool
    {
        return (bool) $this->requires_reference;
    }

    public function providerKey(): ?string
    {
        return $this->provider;
    }

    public function takesTender(): bool
    {
        return $this->channel->takesTender();
    }
}
