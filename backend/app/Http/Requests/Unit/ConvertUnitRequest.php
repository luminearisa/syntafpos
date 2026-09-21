<?php

namespace App\Http\Requests\Unit;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Query-string contract of GET /api/v1/units/{unit}/convert.
 */
class ConvertUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'to' => ['required', 'integer', 'exists:units,id'],
            // Quantities are exact decimals, never floats.
            'quantity' => ['required', 'numeric', 'min:0', 'decimal:6'],
        ];
    }
}
