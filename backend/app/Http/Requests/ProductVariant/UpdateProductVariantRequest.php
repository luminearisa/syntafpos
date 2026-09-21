<?php

namespace App\Http\Requests\ProductVariant;

use App\Models\AttributeValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $variant = $this->route('product_variant');
        $companyId = $variant?->company_id;

        return [
            'sku' => ['sometimes', 'required', 'string', 'max:128', Rule::unique('product_variants', 'sku')->where('company_id', $companyId)->ignore($variant?->id)],
            'barcode' => ['nullable', 'string', 'max:128'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'attribute_value_ids' => ['nullable', 'array'],
            'attribute_value_ids.*' => ['integer', Rule::in($this->companyAttributeValueIds($companyId))],
        ];
    }

    public function messages(): array
    {
        return [
            'attribute_value_ids.*.in' => 'One or more attribute values do not belong to this company.',
        ];
    }

    /**
     * @return list<int>
     */
    private function companyAttributeValueIds(?int $companyId): array
    {
        return AttributeValue::query()
            ->whereHas('attribute', fn ($q) => $q->where('company_id', $companyId))
            ->pluck('id')
            ->all();
    }
}
