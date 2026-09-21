<?php

namespace App\Http\Requests\Customer;

use App\Enums\CustomerType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
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
            'customer_group_id' => ['nullable', 'integer', Rule::exists('customer_groups', 'id')->where('company_id', $companyId)],
            'price_list_id' => ['nullable', 'integer', Rule::exists('price_lists', 'id')->where('company_id', $companyId)],
            'customer_code' => ['required', 'string', 'max:64', Rule::unique('customers', 'customer_code')->where('company_id', $companyId)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['nullable', 'string', Rule::in(array_map(fn (CustomerType $type) => $type->value, CustomerType::cases()))],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'city' => ['nullable', 'string', 'max:255'],
            'province' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:64'],
            'postal_code' => ['nullable', 'string', 'max:16'],
            'tax_number' => ['nullable', 'string', 'max:64'],
            'credit_limit' => ['nullable', 'numeric', 'min:0', 'decimal:4'],
            'payment_terms' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'birthday' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
