<?php

namespace App\Http\Requests\StockOpname;

use App\Models\StockOpname;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStockOpnameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var StockOpname|null $opname */
        $opname = $this->route('stock_opname');
        $companyId = $opname?->company_id ?? $this->integer('company_id');

        return [
            'branch_id' => [
                'nullable',
                'integer',
                Rule::exists('branches', 'id')->where('company_id', $companyId),
            ],
            'warehouse_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('warehouses', 'id')->where('company_id', $companyId),
            ],
            'location_id' => [
                'nullable',
                'integer',
                Rule::exists('warehouse_locations', 'id')->where('warehouse_id', $this->warehouse_id ?? $opname?->warehouse_id),
            ],
            'opname_date' => ['sometimes', 'required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],

            // Counted quantities land here while the sheet is open. The service
            // recomputes difference from the frozen system_quantity.
            'items' => ['sometimes', 'required', 'array'],
            'items.*.id' => ['nullable', 'integer', Rule::exists('stock_opname_items', 'id')->where('stock_opname_id', $opname?->id)],
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
            'items.*.counted_quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
