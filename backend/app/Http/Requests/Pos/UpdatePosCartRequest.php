<?php

namespace App\Http\Requests\Pos;

use App\Enums\DiscountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Header edits on a working cart: attach a customer, set the cart-level
 * discount, leave a note or a label for the held-cart queue.
 *
 * Money entered here is an input, not a total — discount_input and
 * other_charges feed the calculation service, which derives every stored
 * total. A client cannot set a grand total through this endpoint.
 */
class UpdatePosCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = $this->route('cart')?->company_id;

        return [
            'customer_id' => [
                'nullable',
                'integer',
                Rule::exists('customers', 'id')
                    ->where('company_id', $companyId)
                    ->where('is_active', true),
            ],
            'clear_customer' => ['boolean'],
            'label' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'discount_input' => ['nullable', 'numeric', 'decimal:0,4', 'min:0'],
            'discount_type' => ['nullable', Rule::enum(DiscountType::class)],
            'other_charges' => ['nullable', 'numeric', 'decimal:0,4', 'min:0'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // "Clear the customer" is an explicit instruction rather than omitting
        // the field, so a cashier can un-attach a customer without ambiguity.
        if ($this->boolean('clear_customer')) {
            $this->merge(['customer_id' => null]);
        }
    }
}
