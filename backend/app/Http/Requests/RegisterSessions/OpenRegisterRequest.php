<?php

namespace App\Http\Requests\RegisterSessions;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Open a register: name the drawer, the person working it, and the float in it.
 *
 * The service does the interesting validation — which register this user may work,
 * whether it is already open, who may be attributed a shift — so what is checked
 * here is shape only. Duplicating those rules in a rule set would produce two
 * answers to one question, and the one the client sees would be whichever ran
 * first.
 */
class OpenRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The controller authorises against the policy, as every other request in
        // this module does: one place decides what a permission means.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'register_id' => ['sometimes', 'integer', 'exists:registers,id'],
            'cashier_id' => ['sometimes', 'integer', 'exists:users,id'],
            // String-typed amounts throughout: a JSON float opened as 2e5 would be
            // written to a decimal(20,4) column through a lossy cast.
            'opening_balance' => ['required', 'numeric', 'gt:0', 'max:99999999999999'],
            'opened_at' => ['sometimes', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'opening_balance.required' => 'Enter the float put in the drawer.',
            'opening_balance.gt' => 'Opening cash must be more than zero. A register opened with no float cannot be reconciled.',
        ];
    }
}
