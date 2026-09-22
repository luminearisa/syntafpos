<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Enums\SaleReturnStatus;
use App\Enums\SaleStatus;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\DecimalMath;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The sales return engine: goods come back, stock goes back on the shelf.
 *
 * A return is written against a sale that actually shipped stock, priced from
 * the sale's own frozen lines, posted through the Phase 2 ledger, and completed
 * in one database transaction. That atomicity is the whole promise: a return that
 * fails half-way must leave no slip, no movement and no reduced returnable
 * quantity behind, or the next attempt would be refused as an over-return for
 * goods that never came back.
 *
 * Two quantities meet here and must never be confused. What the customer *bought*
 * is the sale line's own quantity, fixed at checkout. What has *already come back*
 * is the sum of the lines of every completed return. A return is allowed only for
 * the difference, and the check runs against the sale's locked row, because two
 * refund clerks on one invoice is a race a per-request read would lose.
 *
 * Money is derived, never accepted from the client. Each return line is the
 * original sale line's value scaled to the returned quantity, and the order-level
 * discount, charges and rounding are spread across the lines in the same
 * proportion, so a full return refunds exactly what was paid and a partial one
 * refunds its fair share. The cost basis each line left at is captured too: Phase 4
 * reverses COGS from it without having to reconstruct the movement.
 */
class SaleReturnService
{
    public function __construct(
        private InventoryService $inventory,
        private NumberingService $numbering,
        private AuditService $audit,
    ) {}

    /**
     * Receive goods back against a sale and post them to stock.
     *
     * @param  array<string, mixed>  $input
     */
    public function store(Sale $sale, User $user, array $input): SaleReturn
    {
        $reason = trim((string) ($input['reason'] ?? ''));

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'A sales return needs a reason.',
            ]);
        }

        return DB::transaction(function () use ($sale, $user, $input, $reason) {
            /** @var Sale $locked */
            $locked = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();

            $this->guardSaleCanBeReturned($locked);

            $requested = $this->requestedQuantities($input['items'] ?? []);
            $locked->load('items');

            $warehouseId = $this->resolveWarehouse($locked, $input['warehouse_id'] ?? null);

            $lines = [];
            $subtotal = '0';
            $discountTotal = '0';
            $taxTotal = '0';
            $costTotal = '0';

            foreach ($requested as $saleItemId => $quantity) {
                /** @var SaleItem|null $item */
                $item = $locked->items->firstWhere('id', $saleItemId);

                if (! $item) {
                    throw ValidationException::withMessages([
                        'items' => "Sale line {$saleItemId} does not belong to sale {$locked->number}.",
                    ]);
                }

                $remaining = $item->returnableQuantity();

                if (bccomp($quantity, $remaining, 6) > 0) {
                    throw ValidationException::withMessages([
                        'items' => sprintf(
                            '%s: %s already returned out of %s; only %s can still come back.',
                            $item->product_name,
                            $this->trim($item->returnedQuantity()),
                            $this->trim((string) $item->quantity),
                            $this->trim($remaining)
                        ),
                    ]);
                }

                $cost = $this->costBasis($locked, $item);

                $line = $this->priceLine($item, $quantity, $cost);
                $lines[] = $line;

                $subtotal = DecimalMath::add($subtotal, $line['line_subtotal']);
                $discountTotal = DecimalMath::add($discountTotal, $line['discount_amount']);
                $taxTotal = DecimalMath::add($taxTotal, $line['tax_amount']);
                $costTotal = DecimalMath::add($costTotal, $line['total_cost']);
            }

            $this->distributeOrderLevelAdjustments($locked, $lines);

            $grandTotal = '0';

            foreach ($lines as $line) {
                $grandTotal = DecimalMath::add($grandTotal, $line['line_total']);
            }

            $saleReturn = SaleReturn::create([
                'company_id' => $locked->company_id,
                'branch_id' => $locked->branch_id,
                'warehouse_id' => $warehouseId,
                'sale_id' => $locked->id,
                'register_id' => $locked->register_id,
                'register_session_id' => $locked->register_session_id,
                'customer_id' => $locked->customer_id,
                'returned_by' => $user->id,
                // Company-level sequence, like the sale's: the unique index is
                // (company_id, number).
                'number' => $this->numbering->next('return', $locked->company_id),
                'return_date' => isset($input['return_date']) ? (string) $input['return_date'] : now()->toDateString(),
                'status' => SaleReturnStatus::Draft,
                'reason' => $reason,
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,
                'tax_total' => $taxTotal,
                'grand_total' => $grandTotal,
                'cost_total' => $costTotal,
                'currency' => $locked->currency,
                'notes' => isset($input['notes']) ? trim((string) $input['notes']) ?: null : null,
            ]);

            foreach ($lines as $line) {
                $saleReturn->items()->create($line);
            }

            // Stock returns through the same engine everything else uses, inside
            // the same transaction, so the slip and the ledger commit together.
            $this->postStock($saleReturn, $locked, $user, $warehouseId);

            $saleReturn->forceFill([
                'status' => SaleReturnStatus::Completed,
                'posted_at' => now(),
            ])->save();

            $this->audit->record('sale_return.create', 'sale_return', $saleReturn->id, null, [
                'number' => $saleReturn->number,
                'sale_number' => $locked->number,
                'grand_total' => (string) $saleReturn->grand_total,
                'lines' => count($lines),
                'reason' => $reason,
            ], $saleReturn->company_id, $user->id);

            return $saleReturn->fresh(['items', 'sale']);
        });
    }

    /**
     * A sale has to have actually shipped goods for anything to come back.
     *
     * An unposted ticket never moved stock, so a return against it would add
     * quantity the shop never sold; a cancelled one has already had its stock put
     * back once. Both are refused with the reason, rather than silently booking a
     * second reversal.
     */
    private function guardSaleCanBeReturned(Sale $sale): void
    {
        if ($sale->status === SaleStatus::Cancelled) {
            throw ValidationException::withMessages([
                'sale' => "Sale {$sale->number} is cancelled; its stock has already been returned.",
            ]);
        }

        if (! $sale->stockPosted()) {
            throw ValidationException::withMessages([
                'sale' => "Sale {$sale->number} has not posted stock yet, so there is nothing to return.",
            ]);
        }
    }

    /**
     * Aggregate the requested lines by sale line, refusing anything malformed.
     *
     * Two entries for the same line are summed before the over-return check, so a
     * client cannot smuggle 6 + 6 returns past a 10-quantity sale by splitting
     * them across the payload.
     *
     * @return array<int, string> sale_item_id => quantity
     */
    private function requestedQuantities(mixed $items): array
    {
        if (! is_array($items) || $items === []) {
            throw ValidationException::withMessages([
                'items' => 'A sales return needs at least one line.',
            ]);
        }

        $requested = [];

        foreach ($items as $index => $item) {
            if (! is_array($item) || empty($item['sale_item_id'])) {
                throw ValidationException::withMessages([
                    "items.{$index}.sale_item_id" => 'Every return line needs the sale line it reverses.',
                ]);
            }

            $id = (int) $item['sale_item_id'];
            $quantity = trim((string) ($item['quantity'] ?? ''));

            if (! preg_match('/^\d+(\.\d{1,6})?$/', $quantity) || bccomp($quantity, '0', 6) <= 0) {
                throw ValidationException::withMessages([
                    "items.{$index}.quantity" => 'A return quantity must be more than zero, with at most six decimals.',
                ]);
            }

            $requested[$id] = DecimalMath::add($requested[$id] ?? '0', $quantity, 6);
        }

        return $requested;
    }

    /**
     * Price one return line from the sale line it reverses.
     *
     * @return array<string, mixed>
     */
    private function priceLine(SaleItem $item, string $quantity, string $unitCost): array
    {
        $ratio = DecimalMath::div($quantity, (string) $item->quantity, 6);

        $gross = DecimalMath::mul($quantity, (string) $item->unit_price);
        $discountAmount = DecimalMath::mul((string) $item->discount_amount, $ratio);
        $taxAmount = DecimalMath::mul((string) $item->tax_amount, $ratio);
        $lineValue = DecimalMath::mul((string) $item->line_total, $ratio);

        return [
            'sale_item_id' => $item->id,
            'company_id' => $item->company_id,
            'product_id' => $item->product_id,
            'product_variant_id' => $item->product_variant_id,
            'unit_id' => $item->unit_id,
            'tax_id' => $item->tax_id,
            'product_name' => $item->product_name,
            'product_sku' => $item->product_sku,
            'variant_name' => $item->variant_name,
            'unit_code' => $item->unit_code,
            'quantity' => $quantity,
            'unit_price' => (string) $item->unit_price,
            'discount' => (string) $item->discount,
            'discount_type' => $item->discount_type ?? 'amount',
            'discount_amount' => $discountAmount,
            'tax_rate' => (string) $item->tax_rate,
            'tax_mode' => (string) $item->tax_mode,
            'tax_amount' => $taxAmount,
            'line_subtotal' => $gross,
            // Overwritten by distributeOrderLevelAdjustments() with this line's
            // share of the order-level discount, charges and rounding.
            'line_total' => $lineValue,
            'unit_cost' => $unitCost,
            'total_cost' => DecimalMath::mul($quantity, $unitCost),
            'restock' => true,
            'reason' => null,
            'notes' => null,
        ];
    }

    /**
     * Spread the order-level adjustments across the returned lines.
     *
     * A sale's grand total is not the sum of its line totals: a header discount,
     * other charges and rounding sit above the lines. A full return must give back
     * the grand total and a partial one its fair share, so the difference between
     * the sale's line-value sum and its grand total is allocated across the return
     * lines in proportion to their value. Without this a customer returning
     * everything on a discounted ticket would be refunded more than they paid.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function distributeOrderLevelAdjustments(Sale $sale, array &$lines): void
    {
        $saleLineValue = '0';

        foreach ($sale->items as $item) {
            $saleLineValue = DecimalMath::add($saleLineValue, (string) $item->line_total);
        }

        if (bccomp($saleLineValue, '0', 4) === 0 || $lines === []) {
            return;
        }

        $returnedValue = '0';

        foreach ($lines as $line) {
            $returnedValue = DecimalMath::add($returnedValue, $line['line_total']);
        }

        $orderAdjustment = DecimalMath::sub((string) $sale->grand_total, $saleLineValue);
        $returnedAdjustment = DecimalMath::mul($orderAdjustment, DecimalMath::div($returnedValue, $saleLineValue, 8), 4);

        if (bccomp($returnedAdjustment, '0', 4) === 0) {
            return;
        }

        $allocated = '0';

        foreach ($lines as $index => $line) {
            // The last line takes whatever rounding is left, so the allocated
            // shares always add back up to the exact header figure.
            $share = $index === array_key_last($lines)
                ? DecimalMath::sub($returnedAdjustment, $allocated)
                : DecimalMath::mul($returnedAdjustment, DecimalMath::div($line['line_total'], $returnedValue, 8), 4);

            $allocated = DecimalMath::add($allocated, $share, 4);
            $lines[$index]['line_total'] = DecimalMath::add($line['line_total'], $share);
        }
    }

    /**
     * The weighted average the goods left at, read off the original movement.
     *
     * A catalogue change since the sale must not move the cost basis, and the
     * ledger is the only place that recorded it. A line whose movement is gone —
     * an untracked product that never booked — values at zero.
     */
    private function costBasis(Sale $sale, SaleItem $item): string
    {
        if ($item->product_id === null) {
            return '0';
        }

        $movement = StockMovement::query()
            ->where('reference_type', Sale::class)
            ->where('reference_id', $sale->id)
            ->where('movement_type', MovementType::Sale)
            ->where('product_id', $item->product_id)
            ->where('product_variant_id', $item->product_variant_id)
            ->orderBy('id')
            ->first();

        return $movement ? (string) $movement->unit_cost : '0';
    }

    /**
     * Put the restocked lines back through the inventory engine.
     */
    private function postStock(SaleReturn $saleReturn, Sale $sale, User $user, int $warehouseId): void
    {
        $saleReturn->load('items');

        $where = [
            'company_id' => $sale->company_id,
            'branch_id' => $sale->branch_id,
            'warehouse_id' => $warehouseId,
            'location_id' => null,
        ];

        foreach ($saleReturn->items as $line) {
            if (! $line->restock || bccomp((string) $line->quantity, '0', 6) === 0) {
                continue;
            }

            $product = $line->product_id === null ? null : Product::query()->find($line->product_id);

            // An untracked product (a service, a fee) carries no stock, and a line
            // whose product was deleted has no balance to return to. Both are
            // still recorded on the slip, just not booked.
            if (! $product || ! $product->track_inventory) {
                continue;
            }

            $unitId = $line->unit_id ?? $product->default_unit_id;

            if (! $unitId) {
                throw ValidationException::withMessages([
                    'items' => "{$line->product_name} has no unit to return stock in.",
                ]);
            }

            $this->inventory->move(
                [
                    'product_id' => $line->product_id,
                    'product_variant_id' => $line->product_variant_id,
                    'unit_id' => $unitId,
                    'quantity' => (string) $line->quantity,
                ],
                MovementType::SaleReturn,
                $where,
                $saleReturn,
                (string) $line->unit_cost,
                $user->id,
                "Return {$saleReturn->number} of sale {$sale->number}"
            );
        }
    }

    private function resolveWarehouse(Sale $sale, mixed $requested): int
    {
        $warehouseId = $requested !== null ? (int) $requested : $sale->warehouse_id;

        if ($warehouseId === null) {
            throw ValidationException::withMessages([
                'warehouse_id' => 'A return needs a warehouse to put the goods back into.',
            ]);
        }

        $exists = Warehouse::query()
            ->where('company_id', $sale->company_id)
            ->whereKey($warehouseId)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'warehouse_id' => 'That warehouse does not belong to this company.',
            ]);
        }

        return $warehouseId;
    }

    /**
     * A decimal string with trailing zeros trimmed, for a message a person reads.
     */
    private function trim(string $value): string
    {
        $value = rtrim(rtrim($value, '0'), '.');

        return $value === '' ? '0' : $value;
    }
}
