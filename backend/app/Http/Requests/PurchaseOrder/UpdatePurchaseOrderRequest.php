<?php

namespace App\Http\Requests\PurchaseOrder;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = $this->integer('company_id') ?: $this->route('purchase_order')?->company_id;

        return [
            'company_id' => ['sometimes', 'required', 'integer', 'exists:companies,id'],
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
            'supplier_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('suppliers', 'id')->where('company_id', $companyId),
            ],
            'order_date' => ['sometimes', 'required', 'date'],
            'expected_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'payment_terms' => ['nullable', 'integer', 'min:0', 'max:365'],
            'currency' => ['nullable', 'string', 'max:8'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'discount_total' => ['nullable', 'decimal:4', 'min:0'],
            'shipping_cost' => ['nullable', 'decimal:4', 'min:0'],
            'other_charges' => ['nullable', 'decimal:4', 'min:0'],

            // Lines may be replaced, but never with an empty set.
            'items' => ['sometimes', 'required', 'array', 'min:1'],
            'items.*.product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')
                    ->where('company_id', $companyId)
                    ->where('is_purchasable', true),
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
            'items.*.tax_id' => [
                'nullable',
                'integer',
                Rule::exists('taxes', 'id')->where('company_id', $companyId),
            ],
            'items.*.description' => ['nullable', 'string', 'max:1000'],
            'items.*.quantity' => ['required', 'decimal:6', 'gt:0'],
            'items.*.unit_price' => ['required', 'decimal:4', 'min:0'],
            'items.*.discount' => ['nullable', 'decimal:4', 'min:0'],
            'items.*.discount_type' => ['nullable', Rule::in(['amount', 'percent'])],
            'items.*.tax_rate' => ['nullable', 'decimal:4', 'min:0'],
        ];
    }
}
