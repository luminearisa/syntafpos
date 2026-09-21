<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tender against a sale — the payment foundation.
 *
 * This is the abstraction the work order asks for: money in is recorded as a
 * numbered row on the transaction, so `paid_total`, the balance owed and the
 * status machine all read facts the server wrote. What is deliberately absent is
 * any provider call. Subphase 3.8 fills in `reference` and the Pending →
 * Completed capture for card, wallet and bank channels; a cash tender is already
 * final the moment it is recorded.
 */
class SalePayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_id', 'company_id', 'register_id', 'received_by',
        'number', 'method', 'amount', 'tendered', 'change', 'reference', 'notes',
    ];

    protected $attributes = [
        'method' => 'cash',
        'status' => 'completed',
    ];

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'amount' => 'decimal:4',
            'tendered' => 'decimal:4',
            'change' => 'decimal:4',
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
}
