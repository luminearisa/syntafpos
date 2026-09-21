<?php

namespace App\Http\Requests\StockAdjustment;

use App\Enums\AdjustmentReason;
use App\Enums\AdjustmentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = $this->integer('company_id');

        return [
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'branch_id' => [
                'nullable',
                'integer',
                Rule::exists('branches', 'id')->where('company_id', $companyId),
            ],
            'warehouse_id' => [
                'required',
                'integer',
                Rule::exists('warehouses', 'id')->where('company_id', $companyId),
            ],
            'location_id' => [
                'nullable',
                'integer',
                Rule::exists('warehouse_locations', 'id')->where('warehouse_id', $this->warehouse_id),
            ],
            'adjustment_date' => ['required', 'date'],
            'adjustment_type' => ['required', 'string', Rule::in(array_map(fn (AdjustmentType $type) => $type->value, AdjustmentType::cases()))],
            'reason' => ['required', 'string', Rule::in(array_map(fn (AdjustmentReason $reason) => $reason->value, AdjustmentReason::cases()))],
            'notes' => ['nullable', 'string', 'max:2000'],

            'items' => ['required', 'array', 'min:1'],
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
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
