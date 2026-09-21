<?php

namespace App\Http\Requests\PaymentMethods;

use App\Enums\PaymentChannel;
use App\Models\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Change how a method behaves at the counter.
 *
 * `code` may be retuned to stay unique and `channel` may be corrected while a
 * method is still unused; the controller refuses a channel change once money has
 * gone through the method. A QRIS button reclassified as cash would leave its past
 * tenders describing change from a drawer that never opened, and nothing on the
 * payment rows would say so. That refusal is the same protection Phase 3.2 bought
 * by refusing to let a product rename rewrite a sale line.
 */
class UpdatePaymentMethodRequest extends FormRequest
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
        /** @var PaymentMethod $method */
        $method = $this->route('payment_method');

        return [
            'code' => [
                'sometimes', 'required', 'string', 'max:32',
                Rule::unique('payment_methods', 'code')
                    ->where('company_id', $method->company_id)
                    ->ignore($method->id),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'channel' => ['sometimes', 'required', Rule::enum(PaymentChannel::class)],
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
            'code.unique' => 'This shop already has a payment method with that code.',
        ];
    }
}
