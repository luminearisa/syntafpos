<?php

namespace App\Http\Requests\UnitConversion;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUnitConversionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'from_unit_id' => [
                'required',
                'integer',
                Rule::exists('units', 'id')->where('company_id', $this->company_id),
            ],
            'to_unit_id' => [
                'required',
                'integer',
                Rule::exists('units', 'id')->where('company_id', $this->company_id),
                'different:from_unit_id',
            ],
            // Conversions multiply, so any factor above zero is well defined.
            // The column is decimal(20,10), so the scale is capped to match it.
            'factor' => ['required', 'numeric', 'gt:0', 'decimal:10', 'max:9999999999'],
        ];
    }

    public function messages(): array
    {
        return [
            'to_unit_id.different' => 'The target unit must differ from the source unit.',
            'factor.gt' => 'The conversion factor must be greater than zero.',
        ];
    }
}
