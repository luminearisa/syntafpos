<?php

namespace App\Http\Requests\Pos;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Checkout: turn a cart into a sale, taking payment against it.
 *
 * The client names the cart and tenders money; it never sends a total. The
 * server reads the cart's own lines, recomputes every figure and compares the
 * tenders to that, so a tampered payload cannot sell goods it was not shown or
 * settle a ticket for less than it costs.
 *
 * Payments are optional here: a ticket tendered short is a legitimate outcome
 * (a customer at the ATM, a card that will not read) and leaves a Draft or
 * Partially Paid sale for .../complete to finish. The line of where that stops
 * is stock — nothing leaves the shelf until the ticket is settled.
 */
class CheckoutSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The tender block, shared with the completion endpoint: one place decides
     * what a payment may look like.
     *
     * @return array<string, list<string|Enum>>
     */
    public static function paymentRules(): array
    {
        return [
            'payments' => ['nullable', 'array', 'max:10'],
            'payments.*.method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'payments.*.amount' => ['required', 'numeric', 'min:0.0001', 'decimal:0,4'],
            'payments.*.tendered' => ['nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'payments.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function rules(): array
    {
        return [
            'cart_id' => ['required', 'integer'],
            'date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ] + self::paymentRules();
    }

    /**
     * Cash entry is typed by hand at a till, so reject a malformed figure here
     * rather than at the money engine.
     */
    public function messages(): array
    {
        return [
            'payments.*.amount.required' => 'Every payment needs an amount.',
            'payments.*.amount.decimal' => 'Payment amounts take at most four decimals.',
        ];
    }
}
