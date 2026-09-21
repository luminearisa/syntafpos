<?php

namespace App\Http\Requests\ProductPrice;

use App\Enums\PriceType;
use App\Models\PriceList;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreProductPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = PriceList::query()->where('id', $this->integer('price_list_id'))->value('company_id');

        return [
            'price_list_id' => ['required', 'integer', 'exists:price_lists,id'],
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'product_variant_id' => [
                'nullable',
                'integer',
                Rule::exists('product_variants', 'id')
                    ->where('company_id', $companyId)
                    ->where('product_id', $this->integer('product_id')),
            ],
            'price_type' => ['nullable', 'string', Rule::in(array_map(fn (PriceType $type) => $type->value, PriceType::cases()))],
            'price' => ['required', 'numeric', 'min:0'],
            'minimum_price' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'product_variant_id.exists' => 'The selected variant does not belong to this product.',
        ];
    }

    /**
     * One price type per product (and variant) inside a price list. The
     * composite key includes a nullable variant column, which a plain unique
     * rule cannot express portably, so it is checked explicitly here.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->any()) {
                return;
            }

            $variantId = $this->filled('product_variant_id') ? $this->integer('product_variant_id') : null;

            $query = DB::table('product_prices')
                ->where('price_list_id', $this->integer('price_list_id'))
                ->where('product_id', $this->integer('product_id'))
                ->where('price_type', $this->input('price_type', 'retail'));

            $variantId === null
                ? $query->whereNull('product_variant_id')
                : $query->where('product_variant_id', $variantId);

            if ($query->exists()) {
                $validator->errors()->add('price_type', 'This product already has a price of that type in this price list.');
            }
        });
    }
}
