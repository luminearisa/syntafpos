<?php

namespace App\Http\Requests\ProductVariant;

use App\Models\AttributeValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = $this->integer('company_id');

        return [
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'sku' => ['required', 'string', 'max:128', Rule::unique('product_variants', 'sku')->where('company_id', $companyId)],
            'barcode' => ['nullable', 'string', 'max:128'],
            'name' => ['required', 'string', 'max:255'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'attribute_value_ids' => ['nullable', 'array'],
            'attribute_value_ids.*' => ['integer', Rule::in($this->companyAttributeValueIds())],
        ];
    }

    public function messages(): array
    {
        return [
            'attribute_value_ids.*.in' => 'One or more attribute values do not belong to this company.',
        ];
    }

    /**
     * Attribute values are reusable across the company; a variant may only
     * carry options that the company itself defined.
     *
     * @return list<int>
     */
    private function companyAttributeValueIds(): array
    {
        return AttributeValue::query()
            ->whereHas('attribute', fn ($q) => $q->where('company_id', $this->integer('company_id')))
            ->pluck('id')
            ->all();
    }
}
