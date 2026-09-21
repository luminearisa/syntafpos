<?php

namespace App\Http\Requests\Tax;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tax = $this->route('tax');
        $companyId = $tax?->company_id ?? $this->company_id;

        return [
            'code' => ['sometimes', 'required', 'string', 'max:32', Rule::unique('taxes', 'code')->where('company_id', $companyId)->ignore($tax?->id)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'rate' => ['sometimes', 'required', 'numeric', 'min:0', 'decimal:4'],
            'type' => ['nullable', 'string', Rule::in(['inclusive', 'exclusive'])],
            'status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
        ];
    }
}
