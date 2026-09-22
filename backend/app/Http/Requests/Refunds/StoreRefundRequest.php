<?php

namespace App\Http\Requests\Refunds;

use App\Enums\RefundMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Raising a refund against a sale.
 *
 * The amount is checked for shape only. Whether it fits what is still refundable,
 * and how it splits across the sale's tenders, is decided in RefundService against
 * the sale's locked payment rows — the engine is the authority on what a customer
 * may be given back, and a form request cannot see a payment. Allocations are
 * optional: a multi-tender refund that does not name them is split by the engine,
 * deterministically and oldest-first.
 */
class StoreRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string|Enum>>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['nullable', Rule::enum(RefundMethod::class)],
            'reason' => ['required', 'string', 'max:500'],
            'sale_return_id' => ['nullable', 'integer'],
            'external_reference' => ['nullable', 'string', 'max:128'],
            'notes' => ['nullable', 'string', 'max:1000'],

            'allocations' => ['nullable', 'array', 'min:1'],
            'allocations.*.sale_payment_id' => ['required', 'integer'],
            'allocations.*.amount' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
