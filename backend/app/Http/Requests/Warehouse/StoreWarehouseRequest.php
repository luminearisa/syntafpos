<?php

namespace App\Http\Requests\Warehouse;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'code' => ['required', 'string', 'max:64', Rule::unique('warehouses', 'code')->where('company_id', $this->company_id)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'type' => ['nullable', 'string', Rule::in(['main', 'outlet', 'production', 'transit'])],
            'status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
        ];
    }
}
