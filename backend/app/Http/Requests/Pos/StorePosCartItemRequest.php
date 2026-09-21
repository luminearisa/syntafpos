<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adding one product to a cart: either the picked product line, or the code
 * the scanner just read. Exactly one of the two must be present.
 */
class StorePosCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = $this->route('cart')?->company_id;

        return [
            'product_id' => [
                'required_without:barcode',
                'nullable',
                'integer',
                Rule::exists('products', 'id')
                    ->where('company_id', $companyId)
                    ->where('is_sellable', true)
                    ->where('is_active', true),
            ],
            'product_variant_id' => [
                'nullable',
                'integer',
                Rule::exists('product_variants', 'id')->where('product_id', $this->input('product_id')),
            ],
            'barcode' => ['required_without:product_id', 'nullable', 'string', 'max:128'],
            'quantity' => ['nullable', 'numeric', 'decimal:0,6', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'product_id.exists' => 'That product cannot be sold: it is outside this company, inactive, or not marked sellable.',
            'barcode.required_without' => 'Pick a product or scan a barcode.',
        ];
    }
}
