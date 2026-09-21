<?php

namespace App\Http\Requests\PurchaseReturn;

use App\Enums\GoodsReceiptStatus;
use App\Enums\PurchaseReturnStatus;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdatePurchaseReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var PurchaseReturn|null $return */
        $return = $this->route('purchase_return');
        $companyId = $return?->company_id ?? $this->integer('company_id');

        return [
            'branch_id' => [
                'nullable',
                'integer',
                Rule::exists('branches', 'id')->where('company_id', $companyId),
            ],
            'warehouse_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('warehouses', 'id')->where('company_id', $companyId),
            ],
            'supplier_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('suppliers', 'id')->where('company_id', $companyId),
            ],
            'return_date' => ['sometimes', 'required', 'date'],
            'reason' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'items' => ['sometimes', 'required', 'array', 'min:1'],
            'items.*.goods_receipt_item_id' => [
                'nullable',
                'integer',
                function (string $attribute, mixed $value, \Closure $fail) use ($return) {
                    if (! $value) {
                        return;
                    }

                    $receiptId = $return?->goods_receipt_id ?? $this->integer('goods_receipt_id');

                    if (! $receiptId) {
                        $fail("The {$attribute} requires a goods receipt to be linked to the return.");

                        return;
                    }

                    $exists = GoodsReceiptItem::query()
                        ->where('id', $value)
                        ->where('goods_receipt_id', $receiptId)
                        ->exists();

                    if (! $exists) {
                        $fail("The {$attribute} does not belong to the linked goods receipt.");
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
            'items.*.quantity' => ['required', 'numeric', 'min:0'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var PurchaseReturn|null $return */
            $return = $this->route('purchase_return');

            if (! $this->has('items')) {
                return;
            }

            $receiptId = $return?->goods_receipt_id ?? $this->integer('goods_receipt_id');

            if ($receiptId && ! $this->receiptIsPosted($receiptId)) {
                $validator->errors()->add(
                    'goods_receipt_id',
                    'Goods can only be returned against a posted goods receipt.'
                );
            }

            foreach ($this->returnableCaps($receiptId, $return?->id) as $index => $cap) {
                if ($cap === null) {
                    continue;
                }

                $quantity = (string) $this->items[$index]['quantity'];

                if (bccomp($quantity, $cap, 6) > 0) {
                    $validator->errors()->add(
                        "items.{$index}.quantity",
                        "Return quantity ({$quantity}) cannot exceed the quantity still returnable ({$cap})."
                    );
                }
            }
        });
    }

    private function receiptIsPosted(int $receiptId): bool
    {
        return GoodsReceipt::query()
            ->where('id', $receiptId)
            ->where('status', GoodsReceiptStatus::Posted)
            ->exists();
    }

    /**
     * The most each line may still send back, keyed by its index in items.
     *
     * See StorePurchaseReturnRequest: the cap is the receipt line's received
     * quantity less what has already gone back on posted returns. The return
     * being edited itself does not count against its own lines, since its lines
     * are being replaced by this payload.
     *
     * @return array<int, string|null>
     */
    private function returnableCaps(?int $receiptId, ?int $returnId): array
    {
        $linkedIds = collect($this->items)
            ->map(fn (array $item) => $item['goods_receipt_item_id'] ?? null)
            ->filter()
            ->all();

        $received = GoodsReceiptItem::query()
            ->whereIn('id', $linkedIds)
            ->pluck('quantity_received', 'id');

        $alreadyReturned = PurchaseReturnItem::query()
            ->whereIn('goods_receipt_item_id', $linkedIds)
            ->whereHas('purchaseReturn', fn ($q) => $q->where('status', PurchaseReturnStatus::Posted))
            ->when($returnId, fn ($q, $id) => $q->where('purchase_return_id', '!=', $id))
            ->selectRaw('goods_receipt_item_id, SUM(quantity) AS total')
            ->groupBy('goods_receipt_item_id')
            ->pluck('total', 'goods_receipt_item_id');

        $poId = $this->route('purchase_return')?->purchase_order_id ?? $this->integer('purchase_order_id');

        return collect($this->items)
            ->map(function (array $item) use ($received, $alreadyReturned, $poId) {
                $receiptItemId = $item['goods_receipt_item_id'] ?? null;

                if ($receiptItemId) {
                    return bcsub(
                        (string) ($received[$receiptItemId] ?? '0'),
                        (string) ($alreadyReturned[$receiptItemId] ?? '0'),
                        6
                    );
                }

                if (! $poId) {
                    return null;
                }

                $orderItem = PurchaseOrderItem::query()
                    ->where('purchase_order_id', $poId)
                    ->where('product_id', $item['product_id'])
                    ->when(
                        ($item['product_variant_id'] ?? null),
                        fn ($q, $variantId) => $q->where('product_variant_id', $variantId),
                        fn ($q) => $q->whereNull('product_variant_id')
                    )
                    ->first();

                return $orderItem === null ? null : (string) $orderItem->quantity_received;
            })
            ->all();
    }
}
