<?php

namespace App\Http\Requests\Product;

use App\Enums\ProductType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $product = $this->route('product');
        $companyId = $product?->company_id;

        return [
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('company_id', $companyId)],
            'brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')->where('company_id', $companyId)],
            'default_unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')->where('company_id', $companyId)],
            'tax_id' => ['nullable', 'integer', Rule::exists('taxes', 'id')->where('company_id', $companyId)],
            'sku' => ['sometimes', 'required', 'string', 'max:128', Rule::unique('products', 'sku')->where('company_id', $companyId)->ignore($product?->id)],
            'barcode' => ['nullable', 'string', 'max:128'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'image' => ['nullable', 'string', 'max:255'],
            'product_type' => ['nullable', 'string', Rule::in(array_map(fn (ProductType $type) => $type->value, ProductType::cases()))],
            'track_inventory' => ['nullable', 'boolean'],
            'allow_negative_stock' => ['nullable', 'boolean'],
            'is_sellable' => ['nullable', 'boolean'],
            'is_purchasable' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
            'minimum_selling_price' => ['nullable', 'numeric', 'min:0'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'length' => ['nullable', 'numeric', 'min:0'],
            'width' => ['nullable', 'numeric', 'min:0'],
            'height' => ['nullable', 'numeric', 'min:0'],
            'minimum_stock' => ['nullable', 'numeric', 'min:0'],
            'maximum_stock' => ['nullable', 'numeric', 'min:0'],
            'reorder_point' => ['nullable', 'numeric', 'min:0'],
            'reorder_quantity' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
