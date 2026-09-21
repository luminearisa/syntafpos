<?php

namespace App\Models;

use App\Enums\PaymentChannel;
use App\Enums\PaymentStatus;
use App\Support\DecimalMath;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tender against a sale — the record that money came in.
 *
 * A payment is written by PaymentService and by nothing else. That single-writer
 * rule is what makes the three derived figures on a sale trustworthy: `paid_total`
 * is the sum of the payments that count, `change_due` the sum of what the drawer
 * handed back, and the sale's status a consequence of both. A tender counted
 * towards paid money while `countsTowardPaid()` said otherwise, and the takings
 * would be wrong everywhere at once.
 *
 * What the row stores about *how* it was paid is a copy, not a join: `channel` and
 * `method_name` are taken off the configured method when the tender is recorded.
 * A payment method is master data a shop renames, reorders or retires; a payment
 * is a financial record that must say the same thing in 2029 as it did at the
 * counter. `payment_method_id` stays on the row as a lookup for reports that want
 * today's grouping, nullable and nullOnDelete, precisely because it must never
 * become load-bearing for what a receipt prints.
 *
 * `refunded_amount` exists so the money a shop gave back is on the same row as the
 * money it took: Partially Refunded is a payment the customer still owes nothing
 * on but which is no longer the shop's in full. Actually paying that refund out is
 * the returns-and-refunds flow, not this subphase.
 */
class SalePayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_id', 'company_id', 'register_id', 'received_by', 'payment_method_id',
        'number', 'channel', 'method_name', 'amount', 'currency',
        'tendered', 'change', 'reference', 'notes', 'metadata',
    ];

    protected $attributes = [
        'channel' => 'cash',
        'status' => 'pending',
        'currency' => 'IDR',
    ];

    protected function casts(): array
    {
        return [
            'channel' => PaymentChannel::class,
            'status' => PaymentStatus::class,
            'amount' => 'decimal:4',
            'tendered' => 'decimal:4',
            'change' => 'decimal:4',
            'refunded_amount' => 'decimal:4',
            'paid_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(Register::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * The configured method this was taken on, if it still exists.
     *
     * A read for the till's convenience and for reporting — never for rendering a
     * document. Those use `method_name`, so retiring a method cannot blank out a
     * line on a printed receipt.
     */
    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    /**
     * What this tender settled, after any refund against it.
     *
     * The whole engine adds this up rather than `amount`, because a partially
     * refunded card payment is not worth what it was charged for, and counting the
     * original figure would leave a sale reading paid on money that has gone back
     * across the counter.
     */
    public function netAmount(): string
    {
        if (! $this->status?->countsTowardPaid()) {
            return '0.0000';
        }

        return DecimalMath::sub((string) $this->amount, (string) $this->refunded_amount);
    }
}
