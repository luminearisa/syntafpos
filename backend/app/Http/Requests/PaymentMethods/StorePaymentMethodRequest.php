<?php

namespace App\Http\Requests\PaymentMethods;

use App\Enums\PaymentChannel;
use App\Support\BusinessContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Configure a way of taking money.
 *
 * What may be chosen here is deliberately narrower than the columns on the table:
 * a method's *behaviour* with money — can it hand change back, is it a customer's
 * account — is a property of the channel and is not a field, because a shop that
 * could tick "gives change" on a card method would end up with a drawer short at
 * the end of a shift and a receipt describing cash that never left.
 *
 * A provider key must name something actually installed, and must speak this
 * method's channel — checked by the controller, not here, because the list of
 * installed providers is runtime state the registry owns. Refusing at save time is
 * deliberate: a method configured against a gateway nobody has wired will refuse
 * every tender it is ever given, and an owner should hear that while filling the
 * form rather than from a queue of declined payments at the counter.
 */
class StorePaymentMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_id' => ['sometimes', 'integer', 'exists:companies,id'],
            'code' => [
                'required', 'string', 'max:32',
                Rule::unique('payment_methods', 'code')->where('company_id', $this->targetCompanyId()),
            ],
            'name' => ['required', 'string', 'max:255'],
            'channel' => ['required', Rule::enum(PaymentChannel::class)],
            'provider' => ['nullable', 'string', 'max:64', 'alpha_dash'],
            'icon' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:500'],
            'requires_reference' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'settings' => ['nullable', 'array'],
            'settings.*' => ['nullable'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'channel.required' => 'Choose what kind of money this method takes.',
            'code.unique' => 'This shop already has a payment method with that code.',
        ];
    }

    /**
     * The company the row will belong to, and the one `code` must be unique in.
     *
     * Resolved once from the active context so the unique rule and the created row
     * cannot disagree about which shop they are talking about.
     */
    public function targetCompanyId(): int
    {
        return (int) ($this->input('company_id') ?: app(BusinessContext::class)->companyId());
    }
}
