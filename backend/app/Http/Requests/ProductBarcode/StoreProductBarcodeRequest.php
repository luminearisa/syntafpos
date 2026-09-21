<?php

namespace App\Http\Requests\ProductBarcode;

use App\Enums\BarcodeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductBarcodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = $this->integer('company_id');
        $productId = $this->integer('product_id');

        return [
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'product_variant_id' => [
                'nullable',
                'integer',
                Rule::exists('product_variants', 'id')
                    ->where('company_id', $companyId)
                    ->where('product_id', $productId),
            ],
            'code' => ['required', 'string', 'max:128', Rule::unique('product_barcodes', 'code')->where('company_id', $companyId)],
            'type' => ['nullable', 'string', Rule::in(array_map(fn (BarcodeType $type) => $type->value, BarcodeType::cases()))],
        ];
    }

    public function messages(): array
    {
        return [
            'product_variant_id.exists' => 'The selected variant does not belong to this product.',
        ];
    }
}
