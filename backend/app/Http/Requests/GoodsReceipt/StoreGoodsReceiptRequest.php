<?php

namespace App\Http\Requests\GoodsReceipt;

use App\Models\PurchaseOrderItem;
use App\Services\SettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreGoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = $this->integer('company_id');

        return [
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'branch_id' => [
                'nullable',
                'integer',
                Rule::exists('branches', 'id')->where('company_id', $companyId),
            ],
            'warehouse_id' => [
                'required',
                'integer',
                Rule::exists('warehouses', 'id')->where('company_id', $companyId),
            ],
            'supplier_id' => [
                'required',
                'integer',
                Rule::exists('suppliers', 'id')->where('company_id', $companyId),
            ],
            'purchase_order_id' => [
                'nullable',
                'integer',
                Rule::exists('purchase_orders', 'id')->where('company_id', $companyId),
            ],
            'receipt_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],

            // A receipt without lines books nothing, so at least one is required.
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => [
                'nullable',
                'integer',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (! $value) {
                        return;
                    }

                    $poId = $this->filled('purchase_order_id') ? $this->integer('purchase_order_id') : null;

                    if (! $poId) {
                        $fail("The {$attribute} requires a purchase order to be linked to the receipt.");

                        return;
                    }

                    $exists = PurchaseOrderItem::query()
                        ->where('id', $value)
                        ->where('purchase_order_id', $poId)
                        ->exists();

                    if (! $exists) {
                        $fail("The {$attribute} does not belong to the linked purchase order.");
                    }
                },
            ],
            'items.*.product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where('company_id', $companyId),
            ],
            'items.*.product_variant_id' => [
                'nullable',
                'integer',
                Rule::exists('product_variants', 'id')->where('product_id', $this->input('items.*.product_id')),
            ],
            'items.*.unit_id' => [
                'required',
                'integer',
                Rule::exists('units', 'id')->where('company_id', $companyId),
            ],
            'items.*.quantity_ordered' => ['nullable', 'numeric', 'min:0'],
            'items.*.quantity_received' => ['required', 'numeric', 'min:0'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Receiving more than was ordered is a configuration-gated exception
     * (spec §49): refused unless inventory.allow_over_receiving is on, so a
     * typo at the dock cannot quietly inflate stock and the cost basis.
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $allowOver = (bool) app(SettingsService::class)
                ->get('inventory.allow_over_receiving', false, $this->integer('company_id') ?: null);

            if ($allowOver) {
                return;
            }

            $ordered = $this->orderedQuantities();

            foreach ($this->items as $index => $item) {
                $limit = $ordered[$item['purchase_order_item_id'] ?? null] ?? null;

                if ($limit === null) {
                    continue;
                }

                if (bccomp((string) $item['quantity_received'], $limit, 6) > 0) {
                    $validator->errors()->add(
                        "items.{$index}.quantity_received",
                        "Quantity received ({$item['quantity_received']}) cannot exceed the ordered quantity ({$limit}) unless over-receiving is enabled."
                    );
                }
            }
        });
    }

    /**
     * The ordered cap per line, keyed by purchase order item. Lines carrying no
     * purchase_order_item_id have no server-side cap to compare against.
     *
     * @return array<int, string>
     */
    private function orderedQuantities(): array
    {
        $ids = collect($this->items)
            ->map(fn (array $item) => $item['purchase_order_item_id'] ?? null)
            ->filter()
            ->all();

        if (! $ids) {
            return [];
        }

        return PurchaseOrderItem::query()
            ->whereIn('id', $ids)
            ->get()
            ->mapWithKeys(fn (PurchaseOrderItem $item) => [$item->id => (string) $item->quantity])
            ->all();
    }
}
