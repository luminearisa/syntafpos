<?php

namespace App\Http\Requests\Unit;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $unit = $this->route('unit');
        $companyId = $unit?->company_id ?? $this->company_id;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:64'],
            'code' => ['sometimes', 'required', 'string', 'max:32', Rule::unique('units', 'code')->where('company_id', $companyId)->ignore($unit?->id)],
            'type' => ['nullable', 'string', Rule::in(['quantity', 'length', 'weight', 'volume', 'area'])],
            'precision' => ['nullable', 'integer', 'min:0', 'max:6'],
            'is_base' => ['nullable', 'boolean'],
            'status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
        ];
    }
}
