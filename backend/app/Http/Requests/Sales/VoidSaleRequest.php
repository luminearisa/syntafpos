<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Voiding an open transaction.
 *
 * The reason is mandatory here rather than optional as it is on the older cancel
 * path: a void is the formal record that somebody with authority decided a
 * transaction should not stand, and "why" is the part of that record an auditor
 * actually reads. The shape of the reversal — which stock, which tenders — is the
 * service's decision, so the only thing a client supplies is the justification.
 */
class VoidSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
