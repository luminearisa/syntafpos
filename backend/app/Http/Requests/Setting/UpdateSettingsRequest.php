<?php

namespace App\Http\Requests\Setting;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'values' => ['required', 'array', 'min:1'],
            'values.*' => ['nullable'],
        ];
    }

    public function messages(): array
    {
        return [
            'values.required' => 'At least one setting value is required.',
            'values.array' => 'Settings must be provided as a key/value map.',
        ];
    }
}
