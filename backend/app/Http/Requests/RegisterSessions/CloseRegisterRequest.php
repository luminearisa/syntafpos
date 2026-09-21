<?php

namespace App\Http\Requests\RegisterSessions;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Count the drawer and close the shift.
 *
 * Only one number is accepted here besides the time: the count. Everything else
 * the close writes — expected cash, the variance, whether it is over threshold —
 * is computed, because a close that could send its own expected figure is a close
 * that can make any shortage disappear.
 */
class CloseRegisterRequest extends FormRequest
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
            // Required, and zero is allowed: an empty drawer is a fact, not a
            // missing input.
            'actual_balance' => ['required', 'numeric', 'gte:0', 'max:99999999999999'],
            'closed_at' => ['sometimes', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'actual_balance.required' => 'Enter the cash counted in the drawer, or 0 if it is empty.',
            'actual_balance.gte' => 'A drawer cannot be counted as negative.',
        ];
    }
}
