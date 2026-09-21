<?php

namespace App\Http\Requests\Tax;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'code' => ['required', 'string', 'max:32', Rule::unique('taxes', 'code')->where('company_id', $this->company_id)],
            'name' => ['required', 'string', 'max:255'],
            'rate' => ['required', 'numeric', 'min:0', 'decimal:4'],
            'type' => ['nullable', 'string', Rule::in(['inclusive', 'exclusive'])],
            'status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
        ];
    }
}
