<?php

namespace App\Http\Requests\AttributeValue;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAttributeValueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'attribute_id' => [
                'required',
                'integer',
                // A value may only be attached to an attribute of the same
                // company, which is what keeps the two-level tree isolated.
                Rule::exists('attributes', 'id')->where('company_id', $this->company_id),
            ],
            'name' => ['required', 'string', 'max:64'],
            'color' => ['nullable', 'string', 'max:16'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
