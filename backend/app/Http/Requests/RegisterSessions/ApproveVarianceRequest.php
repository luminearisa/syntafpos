<?php

namespace App\Http\Requests\RegisterSessions;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Sign off a variance a cashier could not authorise themselves.
 */
class ApproveVarianceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
