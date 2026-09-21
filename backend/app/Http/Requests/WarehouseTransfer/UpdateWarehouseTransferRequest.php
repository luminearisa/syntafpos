<?php

namespace App\Http\Requests\WarehouseTransfer;

use App\Models\WarehouseTransfer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWarehouseTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var WarehouseTransfer|null $transfer */
        $transfer = $this->route('warehouse_transfer');
        $companyId = $transfer?->company_id ?? $this->integer('company_id');

        return [
            'transfer_date' => ['sometimes', 'required', 'date'],
            'from_warehouse_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('warehouses', 'id')->where('company_id', $companyId),
            ],
            'to_warehouse_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('warehouses', 'id')->where('company_id', $companyId),
            ],
            'from_location_id' => [
                'nullable',
                'integer',
                Rule::exists('warehouse_locations', 'id')->where('warehouse_id', $this->from_warehouse_id ?? $transfer?->from_warehouse_id),
            ],
            'to_location_id' => [
                'nullable',
                'integer',
                Rule::exists('warehouse_locations', 'id')->where('warehouse_id', $this->to_warehouse_id ?? $transfer?->to_warehouse_id),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],

            'items' => ['sometimes', 'required', 'array', 'min:1'],
            'items.*.product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where('company_id', $companyId),
            ],
            'items.*.product_variant_id' => [
                'nullable',
                'integer',
                Rule::exists('product_variants', 'id')->where('product_id', $this->input('items.*.product_id')),
            ],
            'items.*.unit_id' => [
                'required',
                'integer',
                Rule::exists('units', 'id')->where('company_id', $companyId),
            ],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
