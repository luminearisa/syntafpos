<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Support\BusinessContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The single place stock is allowed to change.
 *
 * Every mutation becomes one signed ledger row plus a balance update, inside a
 * database transaction with row locking. Nothing else in the application may
 * write to stock_balances or stock_movements; controllers reach for this
 * service instead, so the invariant holds regardless of the calling module.
 */
class InventoryService
{
    public function __construct(
        protected BusinessContext $context,
        protected AuditService $audit
    ) {}

    /**
     * Record a stock movement and refresh the affected balance.
     *
     * @param  array{product_id: int, product_variant_id?: int|null, unit_id: int, quantity: string}  $line
     * @param  MovementType  $type  Direction is derived from the type's sign.
     * @param  array{company_id: int, branch_id?: int|null, warehouse_id: int, location_id?: int|null}  $where
     */
    public function move(
        array $line,
        MovementType $type,
        array $where,
        Model $reference,
        string $unitCost = '0',
        ?int $userId = null,
        ?string $notes = null
    ): StockMovement {
        $sign = self::signOf($type);
        $quantity = bcmul($line['quantity'], (string) $sign, 6);

        return DB::transaction(function () use ($line, $type, $where, $reference, $unitCost, $userId, $notes, $quantity) {
            $balance = $this->lockBalance($line, $where);

            $newOnHand = bcadd($balance->on_hand, $quantity, 6);
            $incoming = self::isIncoming($type);
            $avgCost = $balance->average_cost;

            if ($incoming && bccomp($quantity, '0', 6) > 0) {
                $avgCost = $this->weightedAverage($balance->on_hand, $balance->average_cost, $quantity, $unitCost);
            }

            $this->guardNegative($line['product_id'], $newOnHand, $where['company_id']);

            $balance->on_hand = $newOnHand;
            $balance->average_cost = $avgCost;
            if ($incoming) {
                $balance->last_cost = $unitCost;
                $balance->incoming = bcadd($balance->incoming, $quantity, 6);
            } else {
                $balance->outgoing = bcadd($balance->outgoing, bcmul($quantity, '-1', 6), 6);
            }
            $balance->last_movement_at = now();
            $balance->save();

            $totalCost = bcmul($quantity, $unitCost, 4);

            return StockMovement::create([
                'company_id' => $where['company_id'],
                'branch_id' => $where['branch_id'] ?? null,
                'warehouse_id' => $where['warehouse_id'],
                'location_id' => $where['location_id'] ?? null,
                'product_id' => $line['product_id'],
                'product_variant_id' => $line['product_variant_id'] ?? null,
                'unit_id' => $line['unit_id'],
                'created_by' => $userId ?? $this->currentUserId(),
                'movement_type' => $type,
                'reference_type' => $reference::class,
                'reference_id' => $reference->id,
                'quantity' => $quantity,
                'unit_cost' => $incoming ? $unitCost : $balance->average_cost,
                'total_cost' => $incoming ? $totalCost : bcmul(bcmul($quantity, '-1', 6), $balance->average_cost, 4),
                'balance_after' => $newOnHand,
                'occurred_at' => now(),
                'notes' => $notes,
            ]);
        });
    }

    /**
     * Reserve quantity so it stops counting as available without leaving stock.
     *
     * Used by downstream phases (sales orders); Phase 2 exposes it so the
     * balance schema is exercised and the reserved/available split is real.
     */
    public function reserve(array $line, array $where, Model $reference, string $quantity): StockBalance
    {
        return DB::transaction(function () use ($line, $where, $quantity) {
            $balance = $this->lockBalance($line, $where);

            if (bccomp(bcsub($balance->on_hand, $balance->reserved, 6), $quantity, 6) < 0) {
                throw new RuntimeException('Insufficient available stock to reserve.');
            }

            $balance->reserved = bcadd($balance->reserved, $quantity, 6);
            $balance->save();

            $this->audit->record(
                'stock.reserve',
                'stock_balance',
                $balance->id,
                null,
                ['quantity' => $quantity],
                $where['company_id']
            );

            return $balance;
        });
    }

    /**
     * Release a previously reserved quantity back to available.
     */
    public function release(array $line, array $where, string $quantity): StockBalance
    {
        return DB::transaction(function () use ($line, $where, $quantity) {
            $balance = $this->lockBalance($line, $where);
            $balance->reserved = max('0', bcsub($balance->reserved, $quantity, 6));
            $balance->save();

            return $balance;
        });
    }

    /**
     * On hand for a product at a warehouse, summed across every location.
     */
    public function onHand(int $productId, ?int $variantId, int $warehouseId, ?int $locationId = null): string
    {
        $query = StockBalance::query()
            ->where('product_id', $productId)
            ->where('product_variant_id', $variantId)
            ->where('warehouse_id', $warehouseId);

        if ($locationId) {
            $query->where('location_id', $locationId);
        }

        // sum() returns a bare number on some drivers; normalise to the
        // precision the rest of the ledger speaks.
        return bcadd((string) ($query->sum('on_hand') ?: '0'), '0', 6);
    }

    /**
     * Fetch or create the balance row for one product at one place, locked.
     */
    protected function lockBalance(array $line, array $where): StockBalance
    {
        $query = StockBalance::query()
            ->where('company_id', $where['company_id'])
            ->where('warehouse_id', $where['warehouse_id'])
            ->where('product_id', $line['product_id'])
            ->where('product_variant_id', $line['product_variant_id'] ?? null)
            ->where('unit_id', $line['unit_id'])
            ->where('location_id', $where['location_id'] ?? null);

        if (DB::getDriverName() !== 'sqlite') {
            $query->lockForUpdate();
        }

        $balance = $query->first();

        if (! $balance) {
            // A missing row is an implicit zero balance; create it inside the
            // same transaction so the movement always lands on a locked row.
            try {
                return StockBalance::create([
                    'company_id' => $where['company_id'],
                    'branch_id' => $where['branch_id'] ?? null,
                    'warehouse_id' => $where['warehouse_id'],
                    'location_id' => $where['location_id'] ?? null,
                    'product_id' => $line['product_id'],
                    'product_variant_id' => $line['product_variant_id'] ?? null,
                    'unit_id' => $line['unit_id'],
                ])->fresh();
            } catch (\Throwable $e) {
                Log::warning('Stock balance create race, refetching', [
                    'product_id' => $line['product_id'],
                    'warehouse_id' => $where['warehouse_id'],
                ]);

                return $query->first() ?? throw new RuntimeException('Unable to resolve stock balance.');
            }
        }

        return $balance;
    }

    /**
     * Weighted average cost of the existing stock plus the new receipt.
     *
     * Kept as bcmath string math: floating point drift on a cost basis would
     * silently misstate inventory valuation across thousands of receipts.
     */
    protected function weightedAverage(string $onHand, string $avgCost, string $incoming, string $incomingCost): string
    {
        $existingValue = bcmul($onHand, $avgCost, 4);
        $incomingValue = bcmul($incoming, $incomingCost, 4);
        $newTotal = bcadd($existingValue, $incomingValue, 4);
        $newQty = bcadd($onHand, $incoming, 6);

        if (bccomp($newQty, '0', 6) <= 0) {
            return '0';
        }

        // bcdiv truncates, which would systematically understate cost basis;
        // a half-up rounding matches how the ledger is later reported.
        return $this->roundHalfUp($newTotal, $newQty, 4);
    }

    protected function roundHalfUp(string $numerator, string $denominator, int $scale): string
    {
        $exponent = bcpow('10', (string) ($scale + 1));
        $scaled = bcmul(bcdiv($numerator, $denominator, $scale + 1), $exponent, 0);

        // bcmath has no rounding primitive, so drop the guard digit and add
        // half of the dropped place to reach half-up.
        $truncated = bcdiv($scaled, '10', 0);
        $remainder = bcmod($scaled, '10');

        if (bccomp($remainder, '5', 0) >= 0) {
            $truncated = bcadd($truncated, '1', 0);
        }

        $divisor = bcpow('10', (string) $scale);

        return bcdiv($truncated, $divisor, $scale);
    }

    /**
     * Refuse to drive a product negative unless it explicitly allows it.
     */
    protected function guardNegative(int $productId, string $newOnHand, int $companyId): void
    {
        if (bccomp($newOnHand, '0', 6) >= 0) {
            return;
        }

        $product = Product::query()
            ->where('id', $productId)
            ->where('company_id', $companyId)
            ->first();

        if (! $product?->allow_negative_stock) {
            throw new RuntimeException('Stock cannot go below zero for this product.');
        }
    }

    protected function currentUserId(): ?int
    {
        return auth()->id();
    }

    /**
     * Whether the movement type adds stock.
     */
    public static function isIncoming(MovementType $type): bool
    {
        return in_array($type, [
            MovementType::Opening,
            MovementType::Purchase,
            MovementType::SaleReturn,
            MovementType::TransferIn,
            MovementType::AdjustmentIn,
            MovementType::ProductionIn,
            MovementType::StockOpname,
        ], true);
    }

    /**
     * +1 for stock-adding types, -1 for stock-removing types.
     */
    public static function signOf(MovementType $type): int
    {
        return self::isIncoming($type) ? 1 : -1;
    }
}
