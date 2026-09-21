<?php

namespace App\Http\Requests\Supplier;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $supplier = $this->route('supplier');

        return [
            'supplier_code' => ['sometimes', 'required', 'string', 'max:64', Rule::unique('suppliers', 'supplier_code')->where('company_id', $supplier?->company_id)->ignore($supplier?->id)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:128'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'city' => ['nullable', 'string', 'max:255'],
            'province' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:64'],
            'postal_code' => ['nullable', 'string', 'max:16'],
            'tax_number' => ['nullable', 'string', 'max:64'],
            'payment_terms' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'credit_limit' => ['nullable', 'numeric', 'min:0', 'decimal:4'],
            'bank_name' => ['nullable', 'string', 'max:128'],
            'bank_account' => ['nullable', 'string', 'max:64'],
            'bank_account_name' => ['nullable', 'string', 'max:128'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
        ];
    }
}
