<?php

namespace App\Http\Requests\ProductPrice;

use App\Enums\PriceType;
use App\Models\PriceList;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProductPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $price = $this->route('product_price');
        $priceListId = $price?->price_list_id;
        $companyId = PriceList::query()->where('id', $priceListId)->value('company_id');
        $productId = $this->filled('product_id') ? $this->integer('product_id') : $price?->product_id;

        return [
            'product_id' => ['sometimes', 'required', 'integer', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'product_variant_id' => [
                'nullable',
                'integer',
                Rule::exists('product_variants', 'id')
                    ->where('company_id', $companyId)
                    ->where('product_id', $productId),
            ],
            'price_type' => ['nullable', 'string', Rule::in(array_map(fn (PriceType $type) => $type->value, PriceType::cases()))],
            'price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'minimum_price' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'product_variant_id.exists' => 'The selected variant does not belong to this product.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->any()) {
                return;
            }

            $price = $this->route('product_price');
            $variantId = $this->filled('product_variant_id') ? $this->integer('product_variant_id') : $price?->product_variant_id;

            $query = DB::table('product_prices')
                ->where('price_list_id', $price?->price_list_id)
                ->where('product_id', $this->input('product_id', $price?->product_id))
                ->where('price_type', $this->input('price_type', $price?->price_type?->value ?? 'retail'))
                ->where('id', '!=', $price?->id);

            $variantId === null
                ? $query->whereNull('product_variant_id')
                : $query->where('product_variant_id', $variantId);

            if ($query->exists()) {
                $validator->errors()->add('price_type', 'This product already has a price of that type in this price list.');
            }
        });
    }
}
