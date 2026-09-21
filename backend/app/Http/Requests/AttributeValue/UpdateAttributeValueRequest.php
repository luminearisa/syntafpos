<?php

namespace App\Http\Requests\AttributeValue;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAttributeValueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $value = $this->route('attributeValue');
        $attributeId = $value?->attribute_id ?? $this->attribute_id;

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:64',
                Rule::unique('attribute_values', 'name')->where('attribute_id', $attributeId)->ignore($value?->id),
            ],
            'color' => ['nullable', 'string', 'max:16'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
