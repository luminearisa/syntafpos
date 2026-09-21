<?php

namespace App\Http\Requests\UnitConversion;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUnitConversionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Directed conversions: swapping endpoints would silently invert the
            // factor, so they are immutable on update.
            'factor' => ['sometimes', 'required', 'numeric', 'gt:0', 'decimal:10', 'max:9999999999'],
        ];
    }

    public function messages(): array
    {
        return [
            'factor.gt' => 'The conversion factor must be greater than zero.',
        ];
    }
}
