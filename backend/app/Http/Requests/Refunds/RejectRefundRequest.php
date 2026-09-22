<?php

namespace App\Http\Requests\Refunds;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Refusing a refund. The reason is mandatory: a rejection is a decision someone
 * made about a customer's money, and the record has to say why.
 */
class RejectRefundRequest extends FormRequest
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
