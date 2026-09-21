<?php

namespace App\Http\Requests\Warehouse;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $warehouseId = $this->route('warehouse')?->id;

        return [
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'code' => ['sometimes', 'required', 'string', 'max:64', Rule::unique('warehouses', 'code')->where('company_id', $this->route('warehouse')?->company_id)->ignore($warehouseId)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'type' => ['nullable', 'string', Rule::in(['main', 'outlet', 'production', 'transit'])],
            'status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
        ];
    }
}
