<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The slice of a refund that came off one tender.
 *
 * A refund without allocations is a figure with no provenance. With them, a
 * sale paid Rp 100.000 in cash and Rp 100.000 by card, refunded Rp 50.000, can
 * be shown to have taken Rp 25.000 off each — and the payment rows carry their
 * own `refunded_amount` so the next refund knows what is still refundable.
 *
 * Rows are append-only like the rest of the sales family: a refund that went
 * wrong is raised again, not edited.
 */
class RefundAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'refund_id', 'sale_payment_id', 'company_id', 'amount', 'currency',
    ];

    protected $attributes = [
        'currency' => 'IDR',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
        ];
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(SalePayment::class, 'sale_payment_id');
    }
}
