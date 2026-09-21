<?php

namespace App\Http\Requests\ProductBarcode;

use App\Enums\BarcodeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductBarcodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $barcode = $this->route('product_barcode');
        $companyId = $barcode?->company_id;
        $productId = $this->filled('product_id') ? $this->integer('product_id') : $barcode?->product_id;

        return [
            'product_id' => ['sometimes', 'required', 'integer', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'product_variant_id' => [
                'nullable',
                'integer',
                Rule::exists('product_variants', 'id')
                    ->where('company_id', $companyId)
                    ->where('product_id', $productId),
            ],
            'code' => ['sometimes', 'required', 'string', 'max:128', Rule::unique('product_barcodes', 'code')->where('company_id', $companyId)->ignore($barcode?->id)],
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
