<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Receiving goods back against a sale.
 *
 * The client names the sale lines and the quantities coming back, plus a reason.
 * It never sends a price: the return engine reads the sale line's own frozen
 * figures and derives what is refundable, exactly as checkout refuses a
 * client-sent total. Everything about whether such a return is *allowed* — whether
 * the sale shipped stock, whether the quantity still has room, which warehouse the
 * goods go to — lives in SaleReturnService next to the locked rows it decides
 * against, because a form request cannot see a sale.
 */
class StoreSaleReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
            'return_date' => ['nullable', 'date'],
            'warehouse_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.sale_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
