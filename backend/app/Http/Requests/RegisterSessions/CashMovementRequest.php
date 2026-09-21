<?php

namespace App\Http\Requests\RegisterSessions;

use App\Enums\CashMovementType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Put money into, or take it out of, an open drawer.
 *
 * There is no `direction` field, on purpose: the direction is a property of the
 * type, and a payload that could say `type: expense, direction: in` would be a
 * payload someone had to read twice to know what it did to the cash.
 */
class CashMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The controller authorises against the shift's policy, as every other
        // request in this module does.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(CashMovementType::class)],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999999'],
            'reason' => ['required', 'string', 'max:500'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:128'],
            'occurred_at' => ['sometimes', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Give a reason: it is the only thing that will explain this money later.',
            'amount.gt' => 'A cash movement must be more than zero. Money that did not move is not a movement.',
        ];
    }
}
