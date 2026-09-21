<?php

namespace App\Http\Requests\Attribute;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAttributeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $attribute = $this->route('attribute');
        $companyId = $attribute?->company_id ?? $this->company_id;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:64', Rule::unique('attributes', 'name')->where('company_id', $companyId)->ignore($attribute?->id)],
            'display_type' => ['nullable', 'string', 'max:24'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
        ];
    }
}
