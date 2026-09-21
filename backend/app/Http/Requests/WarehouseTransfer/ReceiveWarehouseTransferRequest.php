<?php

namespace App\Http\Requests\WarehouseTransfer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Body of a partial receipt: the quantity that has now landed per line.
 *
 * The outstanding cap is enforced in the controller, where the stored transfer
 * lines are already loaded, rather than re-queried per rule.
 */
class ReceiveWarehouseTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer'],
            'items.*.quantity_received' => ['required', 'numeric', 'min:0'],
        ];
    }
}
