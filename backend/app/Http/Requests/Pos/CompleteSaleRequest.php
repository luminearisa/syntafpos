<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Further tenders against a sale that is not yet completed.
 *
 * Same payment shape as checkout, same rule that the client proposes amounts and
 * the server decides whether the ticket is now settled. No cart is involved: the
 * sale already exists and carries its own lines and totals.
 */
class CompleteSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return CheckoutSaleRequest::paymentRules();
    }

    public function messages(): array
    {
        return (new CheckoutSaleRequest)->messages();
    }
}
