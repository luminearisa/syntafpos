<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Editing one cart line. Quantity is the absolute new value, so a cashier can
 * type "3" instead of clicking plus twice; zero removes the line.
 */
class UpdatePosCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quantity' => ['sometimes', 'required', 'numeric', 'decimal:0,6', 'min:0'],
            'discount' => ['sometimes', 'numeric', 'decimal:0,4', 'min:0'],
            'discount_type' => ['sometimes', Rule::in(['amount', 'percent'])],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
