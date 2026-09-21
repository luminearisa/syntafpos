<?php

namespace App\Services;

use App\Enums\DiscountType;
use App\Enums\MovementType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SaleStatus;
use App\Models\PosCart;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\BusinessContext;
use App\Support\DecimalMath;
use App\Support\MoneyFormat;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The sales transaction engine: a cart becomes a numbered, stock-moving sale.
 *
 * The flow the work order names is Cart → Sales Order → Sales Transaction →
 * Payment → Completed, and checkout() walks it in one database transaction:
 *
 *   BEGIN
 *     create sale header (number from the Phase 1 sequence engine)
 *     create sale items   (snapshot copied off the cart lines)
 *     validate stock      (friendly pre-flight, then the engine's locked check)
 *     create stock movements through InventoryService, MovementType::Sale
 *     record payments     (payment foundation only — no gateway until 3.8)
 *   COMMIT
 *
 * Nothing in that list is partial. If stock is short, if a line has no unit to
 * move, if a tender is refused, the whole thing rolls back: no sale row, no
 * movement, no payment, and the sequence increment rolls back with it so a
 * failed checkout does not burn an invoice number. That is the only way a till
 * can promise a cashier that "it failed" means the shop's stock and takings are
 * exactly as they were.
 *
 * Two invariants carry through every method here:
 *  - A sale stores its own copies of product, customer and outlet facts. Reads of
 *    a past sale never join the catalogue, so renaming or repricing a product
 *    cannot rewrite history.
 *  - Money is re-derived from the stored line inputs, never copied from the
 *    client or trusted from the cart. The cart's figures are the preview; the
 *    sale's are the record, computed by the same engine so they agree.
 */
class SaleService
{
    public function __construct(
        private BusinessContext $context,
        private PosCartCalculationService $calculation,
        private InventoryService $inventory,
        private NumberingService $numbering,
        private SettingsService $settings,
        private AuditService $audit,
    ) {}

    /**
     * Turn the working cart into a sale.
     *
     * @param  array<string, mixed>  $input  validated checkout payload (payments, date, notes)
     */
    public function checkout(PosCart $cart, User $user, array $input): Sale
    {
        $this->ensureCartIsWorkable($cart, $user);

        $cart->load('items');

        if ($cart->items->isEmpty()) {
            throw ValidationException::withMessages([
                'cart' => 'There is nothing to sell — the cart is empty.',
            ]);
        }

        $payments = $this->normalisePayments($input['payments'] ?? []);
        $warehouse = $this->resolveWarehouse($cart);

        return DB::transaction(function () use ($cart, $user, $input, $payments, $warehouse) {
            $sale = $this->raiseFromCart($cart, $user, $input, $warehouse);

            // Take the money first: a tender refused by validation aborts here,
            // before a single balance row is touched.
            $this->applyPayments($sale, $user, $payments);

            if ($this->settled($sale)) {
                $this->postStock($sale, $user);
                $this->markCompleted($sale);
            } else {
                // Nothing leaves the counter on an unpaid ticket: a short tender
                // is recorded, and stock waits for complete(). With no tender at
                // all the sale is the order step of the flow — a numbered,
                // snapshotted ticket the cashier can hand over for payment.
                $sale->forceFill([
                    'status' => bccomp((string) $sale->paid_total, '0', 4) > 0
                        ? SaleStatus::PartiallyPaid
                        : SaleStatus::Draft,
                ])->save();
            }

            // The cart is consumed rather than cleared: its lines are now the
            // sale's, and the till must come back empty instead of offering a
            // second checkout of the same goods. Soft delete keeps the draft
            // readable through the sale's pos_cart_id for the audit trail.
            $cart->delete();

            $this->audit->record('sale.checkout', 'sale', $sale->id, null, [
                'number' => $sale->number,
                'grand_total' => (string) $sale->grand_total,
                'status' => $sale->status->value,
            ], $sale->company_id, $user->id);

            return $sale->fresh(['items', 'payments']);
        });
    }

    /**
     * Settle what is still owed on a sale and post it once the balance clears.
     *
     * The endpoint behind this is also the recovery path for a ticket parked in
     * Pending Payment — a card decline, a customer walking to the ATM — so it
     * accepts further tenders and only moves stock when the ticket is actually
     * paid for.
     *
     * @param  array<string, mixed>  $input
     */
    public function complete(Sale $sale, User $user, array $input = []): Sale
    {
        if ($sale->status === SaleStatus::Cancelled) {
            throw ValidationException::withMessages([
                'sale' => "Sale {$sale->number} is cancelled and can no longer be completed.",
            ]);
        }

        if ($sale->status === SaleStatus::Completed) {
            throw ValidationException::withMessages([
                'sale' => "Sale {$sale->number} is already completed.",
            ]);
        }

        $payments = $this->normalisePayments($input['payments'] ?? []);

        return DB::transaction(function () use ($sale, $user, $payments) {
            $sale = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();

            $this->applyPayments($sale, $user, $payments);

            if (! $this->settled($sale)) {
                // Still short. Recorded, but nothing leaves the shelf.
                $sale->forceFill([
                    'status' => bccomp((string) $sale->paid_total, '0', 4) > 0
                        ? SaleStatus::PartiallyPaid
                        : SaleStatus::PendingPayment,
                ])->save();

                return $sale->fresh(['items', 'payments']);
            }

            $this->postStock($sale, $user);
            $this->markCompleted($sale);

            $this->audit->record('sale.complete', 'sale', $sale->id, null, [
                'number' => $sale->number,
                'paid_total' => (string) $sale->paid_total,
            ], $sale->company_id, $user->id);

            return $sale->fresh(['items', 'payments']);
        });
    }

    /**
     * Withdraw a sale, putting back whatever stock had left.
     *
     * Only stock is reversed here. Money is *voided*, not refunded: the payment
     * rows stay on the record with a voided status so the takings trail is
     * unbroken, while the actual cash going back across the counter is a
     * returns-and-refunds flow that belongs to a later subphase, not to this one
     * — the work order is explicit about not building it here.
     *
     * @param  array<string, mixed>  $input
     */
    public function cancel(Sale $sale, User $user, array $input = []): Sale
    {
        if ($sale->status === SaleStatus::Cancelled) {
            throw ValidationException::withMessages([
                'sale' => "Sale {$sale->number} is already cancelled.",
            ]);
        }

        $reason = isset($input['reason']) ? trim((string) $input['reason']) : null;

        return DB::transaction(function () use ($sale, $user, $reason) {
            $sale = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();

            if ($sale->status === SaleStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'sale' => "Sale {$sale->number} is already cancelled.",
                ]);
            }

            $before = $sale->only(['status']);

            // Reverse what actually left, read off the ledger rather than off the
            // lines: a sale may carry an untracked or catalogue-deleted line that
            // was never booked, and returning one of those would add stock that no
            // customer took. Each outgoing quantity comes back at the same place,
            // unit and amount it went out.
            $outgoing = StockMovement::query()
                ->where('reference_type', Sale::class)
                ->where('reference_id', $sale->id)
                ->where('movement_type', MovementType::Sale)
                ->orderBy('id')
                ->get();

            foreach ($outgoing as $movement) {
                $this->inventory->move(
                    [
                        'product_id' => $movement->product_id,
                        'product_variant_id' => $movement->product_variant_id,
                        'unit_id' => $movement->unit_id,
                        // The ledger stores outgoing quantities signed; the
                        // engine applies the direction itself, so it gets a
                        // positive amount.
                        'quantity' => bcmul((string) $movement->quantity, '-1', 6),
                    ],
                    MovementType::SaleReturn,
                    [
                        'company_id' => $movement->company_id,
                        'branch_id' => $movement->branch_id,
                        'warehouse_id' => $movement->warehouse_id,
                        'location_id' => $movement->location_id,
                    ],
                    $sale,
                    '0',
                    $user->id,
                    "Cancellation of sale {$sale->number}"
                );
            }

            foreach ($sale->payments as $payment) {
                if ($payment->status === PaymentStatus::Completed) {
                    $payment->forceFill(['status' => PaymentStatus::Voided])->save();
                }
            }

            // paid_total always equals the sum of the completed tenders on the
            // record, so voiding them takes it back to zero. The money itself is
            // handed across the counter by the cancellation flow, not by this
            // column: refunds are a later subphase, as the work order states.
            $sale->forceFill([
                'status' => SaleStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'cancel_reason' => $reason,
                'paid_total' => '0',
                'change_due' => '0',
            ])->save();

            $this->audit->record('sale.cancel', 'sale', $sale->id, $before, [
                'status' => SaleStatus::Cancelled->value,
                'reason' => $reason,
                'stock_reversed' => $outgoing->count(),
            ], $sale->company_id, $user->id);

            return $sale->fresh(['items', 'payments']);
        });
    }

    /**
     * Create the sale header and its lines from a cart, inside the caller's
     * transaction. Totals are derived here, so a sale never inherits a stale
     * cart figure.
     *
     * @param  array<string, mixed>  $input
     */
    private function raiseFromCart(PosCart $cart, User $user, array $input, Warehouse $warehouse): Sale
    {
        $cart->loadMissing(['customer', 'branch', 'register']);

        $sale = Sale::create([
            'company_id' => $cart->company_id,
            'branch_id' => $cart->branch_id,
            'warehouse_id' => $warehouse->id,
            'register_id' => $cart->register_id,
            'customer_id' => $cart->customer_id,
            'cashier_id' => $user->id,
            'pos_cart_id' => $cart->id,
            // Company-level sequence on purpose: the unique index is
            // (company_id, number), so a per-branch series would collide the
            // moment two outlets each issued their first INV-2026-000001.
            'number' => $this->numbering->next('invoice', $cart->company_id),
            'date' => isset($input['date']) ? (string) $input['date'] : now()->toDateString(),
            'currency' => (string) $this->settings->get('company.currency', $cart->currency ?: 'IDR', $cart->company_id),
            'discount_input' => (string) ($cart->discount_input ?? '0'),
            'discount_type' => $cart->discount_type ?? DiscountType::Amount,
            'other_charges' => (string) ($cart->other_charges ?? '0'),
            'notes' => $this->mergeNotes($cart, $input['notes'] ?? null),

            // Snapshot the parties and the outlet as they are right now.
            'customer_name' => $cart->customer?->name,
            'customer_code' => $cart->customer?->customer_code,
            'customer_phone' => $cart->customer?->phone,
            'customer_email' => $cart->customer?->email,
            'customer_address' => $cart->customer?->address,
            'branch_name' => $cart->branch?->name,
            'branch_address' => $this->formatAddress($cart->branch),
            'branch_phone' => $cart->branch?->phone,
            'register_code' => $cart->register?->code,
        ]);

        foreach ($cart->items as $item) {
            $sale->items()->create([
                'company_id' => $sale->company_id,
                'product_id' => $item->product_id,
                'product_variant_id' => $item->product_variant_id,
                'unit_id' => $item->unit_id,
                'tax_id' => $item->tax_id,
                'product_name' => $item->product_name,
                'product_sku' => $item->product_sku,
                'barcode' => $item->barcode,
                'variant_name' => $item->variant_name,
                'unit_code' => $item->unit_code,
                'quantity' => (string) $item->quantity,
                'unit_price' => (string) $item->unit_price,
                'price_source' => $item->price_source,
                'discount' => (string) $item->discount,
                'discount_type' => $item->discount_type ?? DiscountType::Amount,
                'tax_rate' => (string) $item->tax_rate,
                'tax_mode' => (string) $item->tax_mode,
                'notes' => $item->notes,
            ]);
        }

        // The lines carry their own money before the header adds them up: the
        // receipt and the invoice both print a line's stored total, so a sale
        // whose header was right above zeroed lines would be two documents
        // disagreeing with each other.
        $this->calculation->applyLines($sale);
        $this->calculation->applyTotals($sale);

        return $sale;
    }

    /**
     * Move every line out of stock through the Phase 2 engine.
     *
     * Called inside the caller's transaction, so one short line aborts the whole
     * sale. The pre-flight pass exists so the cashier is told *which* product
     * failed and by how much: the engine's guard is a bare refusal that would
     * otherwise surface as an anonymous error, and it stays the authoritative
     * check because it runs against a locked balance row.
     */
    private function postStock(Sale $sale, User $user): void
    {
        if ($sale->stock_posted_at !== null) {
            return;
        }

        $sale->loadMissing('items');

        $where = [
            'company_id' => $sale->company_id,
            'branch_id' => $sale->branch_id,
            'warehouse_id' => $sale->warehouse_id,
            'location_id' => null,
        ];

        $this->ensureStockAvailable($sale, $where);

        foreach ($sale->items as $item) {
            if (bccomp((string) $item->quantity, '0', 6) === 0) {
                continue;
            }

            $product = $item->product;

            // A line whose product has been hard-deleted from the catalogue has
            // no balance row left to draw from, and the ledger requires a real
            // product id. The snapshot on the sale still stands: what is not
            // there any more cannot be moved out of stock.
            if ($product === null) {
                continue;
            }

            // Untracked products (a service, a gift wrap fee) carry no stock, so
            // posting one would create a balance row that only ever goes
            // negative. The line is still billed, just not deducted.
            if (! $product->track_inventory) {
                continue;
            }

            $unitId = $item->unit_id ?? $product->default_unit_id;

            if (! $unitId) {
                // The movement ledger is per unit; a line without one cannot be
                // booked, and guessing would silently misstate the balance.
                throw ValidationException::withMessages([
                    'items' => "{$item->product_name} has no unit to move stock in.",
                ]);
            }

            try {
                $this->inventory->move(
                    [
                        'product_id' => $item->product_id,
                        'product_variant_id' => $item->product_variant_id,
                        'unit_id' => $unitId,
                        'quantity' => (string) $item->quantity,
                    ],
                    MovementType::Sale,
                    $where,
                    $sale,
                    // A sale does not set a cost basis: the engine keeps the
                    // weighted average it already holds and values the outgoing
                    // quantity at that.
                    '0',
                    $user->id,
                    "Sale {$sale->number}"
                );
            } catch (RuntimeException $e) {
                // The engine holds the row lock, so this is the race the
                // pre-flight could not see: someone else took the stock first.
                throw ValidationException::withMessages([
                    'items' => "Not enough stock for {$item->product_name}: {$e->getMessage()}",
                ]);
            }
        }

        $sale->forceFill(['stock_posted_at' => now()])->save();
    }

    /**
     * Tell the cashier which lines cannot be filled before booking anything.
     */
    private function ensureStockAvailable(Sale $sale, array $where): void
    {
        $short = [];
        $required = [];

        // Two lines of the same product share one balance, so the check has to
        // accumulate rather than compare line by line.
        foreach ($sale->items as $item) {
            $key = $item->product_id.':'.($item->product_variant_id ?? '0').':'.($item->unit_id ?? '0');
            $required[$key] = ['item' => $item, 'quantity' => DecimalMath::add($required[$key]['quantity'] ?? '0', (string) $item->quantity, 6)];
        }

        foreach ($required as $entry) {
            /** @var SaleItem $item */
            $item = $entry['item'];
            $product = $item->product;

            // An untracked product has no balance to run out of, and a line whose
            // product was deleted since the cart was opened cannot be checked —
            // both fall through to the engine, which creates the row it needs.
            if ($product === null || ! $product->track_inventory || $product->allow_negative_stock) {
                continue;
            }

            $onHand = $this->inventory->onHand(
                (int) $item->product_id,
                $item->product_variant_id === null ? null : (int) $item->product_variant_id,
                (int) $where['warehouse_id'],
                null
            );

            if (bccomp($onHand, $entry['quantity'], 6) < 0) {
                $short[] = sprintf(
                    '%s: %s requested, %s in stock at %s',
                    $item->product_name,
                    rtrim(rtrim($entry['quantity'], '0'), '.'),
                    rtrim(rtrim($onHand, '0'), '.'),
                    $this->warehouseLabel($where['warehouse_id'])
                );
            }
        }

        if ($short !== []) {
            throw ValidationException::withMessages(['items' => $short]);
        }
    }

    /**
     * Record tenders against the sale and roll paid_total forward.
     *
     * A tender may never exceed the balance still owed, so over-collecting is
     * not reachable through this path; only cash is tendered, so only cash can
     * produce change.
     *
     * @param  list<array{method: PaymentMethod, amount: string, tendered: string, notes: string|null}>  $payments
     */
    private function applyPayments(Sale $sale, User $user, array $payments): void
    {
        if ($payments === []) {
            return;
        }

        $currency = (string) $this->settings->get('company.currency', $sale->currency ?: 'IDR', $sale->company_id);

        foreach ($payments as $payment) {
            $amount = $payment['amount'];

            if (bccomp($amount, '0', 4) <= 0) {
                throw ValidationException::withMessages([
                    'payments' => 'A payment must be more than zero.',
                ]);
            }

            $balance = DecimalMath::sub((string) $sale->grand_total, (string) $sale->paid_total);

            if (bccomp($amount, $balance, 4) > 0) {
                throw ValidationException::withMessages([
                    'payments' => sprintf(
                        'That payment is larger than the remaining balance of %s.',
                        MoneyFormat::format($balance, $currency)
                    ),
                ]);
            }

            $tendered = $payment['method']->takesTender() && bccomp($payment['tendered'], '0', 4) > 0
                ? $payment['tendered']
                : $amount;

            if (bccomp($tendered, $amount, 4) < 0) {
                throw ValidationException::withMessages([
                    'payments' => 'The cash handed over is less than the amount being paid.',
                ]);
            }

            $change = DecimalMath::sub($tendered, $amount);

            SalePayment::create([
                'sale_id' => $sale->id,
                'company_id' => $sale->company_id,
                'register_id' => $sale->register_id,
                'received_by' => $user->id,
                'number' => $this->numbering->next('payment', $sale->company_id),
                'method' => $payment['method'],
                'amount' => $amount,
                'tendered' => $tendered,
                'change' => $change,
                'notes' => $payment['notes'],
                'status' => PaymentStatus::Completed,
            ]);

            $sale->forceFill([
                'paid_total' => DecimalMath::add((string) $sale->paid_total, $amount),
                'change_due' => DecimalMath::add((string) $sale->change_due, $change),
            ])->save();
        }
    }

    /**
     * Whether the ticket has been paid for in full.
     */
    private function settled(Sale $sale): bool
    {
        return bccomp((string) $sale->paid_total, (string) $sale->grand_total, 4) >= 0;
    }

    /**
     * Close the ticket.
     *
     * paid_total is left as the tenders wrote it — applyPayments() refuses
     * anything above the balance, so over-collecting is not reachable here.
     */
    private function markCompleted(Sale $sale): void
    {
        $sale->forceFill([
            'status' => SaleStatus::Completed,
            'completed_at' => now(),
        ])->save();
    }

    /**
     * Validate and shape the payment payload.
     *
     * @return list<array{method: PaymentMethod, amount: string, tendered: string, notes: string|null}>
     */
    private function normalisePayments(mixed $input): array
    {
        if ($input === null || $input === '' || $input === []) {
            return [];
        }

        if (! is_array($input)) {
            throw ValidationException::withMessages(['payments' => 'Payments must be a list.']);
        }

        // A single payment may be posted as one object instead of an array.
        if (isset($input['method'])) {
            $input = [$input];
        }

        return array_map(function (array $payment, int $index): array {
            $methodValue = (string) ($payment['method'] ?? PaymentMethod::Cash->value);

            try {
                $method = PaymentMethod::from($methodValue);
            } catch (\ValueError) {
                throw ValidationException::withMessages([
                    "payments.{$index}.method" => "Unknown payment method {$methodValue}.",
                ]);
            }

            $amount = isset($payment['amount']) ? (string) $payment['amount'] : '0';

            if (! preg_match('/^-?\d+(\.\d{1,4})?$/', $amount)) {
                throw ValidationException::withMessages([
                    "payments.{$index}.amount" => 'Payment amounts must be a number with at most four decimals.',
                ]);
            }

            $tendered = isset($payment['tendered']) ? (string) $payment['tendered'] : '0';

            if (! preg_match('/^-?\d+(\.\d{1,4})?$/', $tendered)) {
                throw ValidationException::withMessages([
                    "payments.{$index}.tendered" => 'Tendered amounts must be a number with at most four decimals.',
                ]);
            }

            return [
                'method' => $method,
                'amount' => $amount,
                'tendered' => $tendered,
                'notes' => isset($payment['notes']) ? trim((string) $payment['notes']) ?: null : null,
            ];
        }, $input, array_keys($input));
    }

    /**
     * The warehouse stock leaves from: the cart's own, then the request context.
     */
    private function resolveWarehouse(PosCart $cart): Warehouse
    {
        $warehouseId = $cart->warehouse_id ?? $this->context->warehouseId();

        $warehouse = $warehouseId === null
            ? null
            : Warehouse::query()->where('company_id', $cart->company_id)->find($warehouseId);

        if (! $warehouse) {
            throw ValidationException::withMessages([
                'warehouse_id' => 'No warehouse is selected for this register, so stock has nowhere to leave from.',
            ]);
        }

        return $warehouse;
    }

    private function ensureCartIsWorkable(PosCart $cart, User $user): void
    {
        if ($cart->user_id !== $user->id) {
            throw ValidationException::withMessages([
                'cart' => 'That cart belongs to another cashier.',
            ]);
        }

        if (! $cart->isEditable()) {
            throw ValidationException::withMessages([
                'cart' => 'Recall the cart from hold before checking it out.',
            ]);
        }
    }

    /**
     * Keep the cart's note as the transaction's own record, appending whatever
     * the cashier adds at payment.
     */
    private function mergeNotes(PosCart $cart, ?string $note): ?string
    {
        $parts = array_filter([(string) $cart->notes, (string) $note]);

        return $parts === [] ? null : implode("\n", $parts);
    }

    private function formatAddress(mixed $branch): ?string
    {
        if (! $branch) {
            return null;
        }

        $line = implode(', ', array_filter([
            $branch->address,
            $branch->city,
            $branch->province,
        ]));

        return $line === '' ? null : $line;
    }

    private function warehouseLabel(?int $warehouseId): string
    {
        if ($warehouseId === null) {
            return 'this register';
        }

        $warehouse = Warehouse::query()->find($warehouseId);

        return $warehouse?->name ?? 'this register';
    }
}
