<?php

namespace App\Services;

use App\Enums\CartStatus;
use App\Enums\DiscountType;
use App\Enums\TaxType;
use App\Models\PosCart;
use App\Models\PosCartItem;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductVariant;
use App\Models\Tax;
use App\Models\User;
use App\Support\BusinessContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cart lifecycle for the point of sale: open, mutate, hold and recall.
 *
 * Two rules hold across every method here. Stock is never reduced or reserved
 * by a cart — availability is only *read* to warn the cashier, and the real
 * movement belongs to the completed sale in Subphase 3.2. And no money column
 * is ever taken from the client: after each mutation the calculation service
 * recomputes the cart from its lines.
 */
class PosCartService
{
    public function __construct(
        private BusinessContext $context,
        private PriceResolutionService $prices,
        private PosCartCalculationService $calculation,
        private NumberingService $numbering,
        private SettingsService $settings,
        private AuditService $audit,
    ) {}

    /**
     * The cart this cashier is working at the active register, created empty
     * on first touch.
     *
     * The lookup row is write-locked so two requests from the same till cannot
     * each open a cart. (SQLite serialises writers anyway; this is the
     * production-driver guard.)
     */
    public function openWorkingCart(User $user): PosCart
    {
        $companyId = $this->requireCompanyId();
        $registerId = $this->context->registerId();

        return DB::transaction(function () use ($user, $companyId, $registerId) {
            $existing = PosCart::query()
                ->working($companyId, $user->id, $registerId)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $cart = PosCart::create([
                'company_id' => $companyId,
                'branch_id' => $this->context->branchId(),
                'warehouse_id' => $this->context->warehouseId(),
                'register_id' => $registerId,
                'user_id' => $user->id,
                'status' => CartStatus::Active,
                'currency' => (string) $this->settings->get('company.currency', 'IDR', $companyId),
            ]);

            $this->calculation->applyTotals($cart);

            return $cart;
        });
    }

    /**
     * Add a scanned or tapped product to a cart, merging onto an existing line
     * for the same product and variant.
     *
     * @param  array<string, mixed>  $input  validated add-item payload
     */
    public function addItem(PosCart $cart, User $user, array $input): PosCartItem
    {
        $this->ensureWritable($cart);
        $this->ensureSameCashier($cart, $user);

        [$product, $variant] = $this->resolveLineProduct($cart, $input);

        $quantity = (string) ($input['quantity'] ?? '1');
        $existing = $cart->items()
            ->where('product_id', $product->id)
            ->where('product_variant_id', $variant?->id)
            ->first();

        if ($existing) {
            // A second scan of the same code joins the line rather than
            // duplicating it; per-line edits after that are quantity changes.
            $existing->forceFill(['quantity' => bcadd((string) $existing->quantity, $quantity, 6)])->save();
            $this->recalculateLine($existing);
            $this->calculation->applyTotals($cart);

            return $existing->fresh(['product', 'productVariant', 'unit']);
        }

        $priced = $this->prices->resolve($product, $variant?->id, $cart->customer_id);
        // A variant inherits the product's tax: it carries price and stock, not
        // a separate fiscal treatment.
        $tax = Tax::query()->find($product->tax_id);

        $item = $cart->items()->create([
            'company_id' => $cart->company_id,
            'product_id' => $product->id,
            'product_variant_id' => $variant?->id,
            'unit_id' => $product->default_unit_id,
            'tax_id' => $tax?->id,
            'product_name' => $product->name,
            'product_sku' => $variant?->sku ?? $product->sku,
            'barcode' => $variant?->barcode ?? $product->barcode,
            'variant_name' => $variant?->name,
            'unit_code' => $product->defaultUnit?->code,
            'quantity' => $quantity,
            'unit_price' => $priced['price'],
            'price_source' => $priced['source'],
            'tax_rate' => $tax ? (string) $tax->rate : '0',
            'tax_mode' => $tax?->type === TaxType::Inclusive ? 'inclusive' : 'exclusive',
            'notes' => $input['notes'] ?? null,
        ]);

        $this->recalculateLine($item);
        $this->calculation->applyTotals($cart);

        return $item->fresh(['product', 'productVariant', 'unit']);
    }

    /**
     * Change quantity, discount or note on one line. Money is re-derived.
     *
     * Returns null when the line was removed, which is what a quantity of zero
     * means: the cashier cleared the count, so there is nothing left to price.
     *
     * @param  array<string, mixed>  $input
     */
    public function updateItem(PosCart $cart, PosCartItem $item, array $input, User $user): ?PosCartItem
    {
        $this->ensureWritable($cart);
        $this->ensureSameCashier($cart, $user);

        if (isset($input['quantity']) && bccomp((string) $input['quantity'], '0', 6) === 0) {
            // Quantity is the absolute new value, so "0" is an instruction to
            // drop the line rather than a price to zero.
            $item->delete();
            $this->calculation->applyTotals($cart);

            return null;
        }

        $item->fill(array_intersect_key($input, array_flip(['quantity', 'discount', 'discount_type', 'notes'])));
        $item->save();

        $this->recalculateLine($item);
        $this->calculation->applyTotals($cart);

        return $item->fresh(['product', 'productVariant', 'unit']);
    }

    public function removeItem(PosCart $cart, PosCartItem $item, User $user): void
    {
        $this->ensureWritable($cart);
        $this->ensureSameCashier($cart, $user);

        $item->delete();
        $this->calculation->applyTotals($cart);
    }

    public function clearItems(PosCart $cart, User $user): PosCart
    {
        $this->ensureWritable($cart);
        $this->ensureSameCashier($cart, $user);

        $cart->items()->delete();
        $this->calculation->applyTotals($cart);

        return $cart->fresh();
    }

    /**
     * Apply header fields: customer, notes, label, cart-level discount.
     *
     * @param  array<string, mixed>  $input
     */
    public function updateHeader(PosCart $cart, User $user, array $input): PosCart
    {
        $this->ensureWritable($cart);
        $this->ensureSameCashier($cart, $user);

        $cart->fill(array_intersect_key($input, array_flip([
            'customer_id', 'notes', 'label', 'discount_input', 'discount_type', 'other_charges',
        ])));
        $cart->save();

        $this->calculation->applyTotals($cart);

        return $cart->fresh();
    }

    /**
     * Park the cart so another customer can be served. A recall code is
     * assigned here — not at open — so the sequence is never burned on carts
     * that are abandoned mid-transaction.
     */
    public function hold(PosCart $cart, User $user): PosCart
    {
        $this->ensureWritable($cart);
        $this->ensureSameCashier($cart, $user);

        if ($cart->items()->doesntExist()) {
            // An empty cart is not worth a sequence number; clear and re-open instead.
            throw ValidationException::withMessages([
                'cart' => 'There is nothing on hold yet — add items first.',
            ]);
        }

        $cart->forceFill([
            'status' => CartStatus::Held,
            'number' => $this->numbering->next('pos_cart', $cart->company_id),
            'held_at' => now(),
        ])->save();

        $this->audit->record('pos_cart.hold', 'pos_cart', $cart->id, null, ['number' => $cart->number], $cart->company_id);

        return $cart;
    }

    /**
     * Bring a parked cart back to the till.
     *
     * Any other open work must be parked first: silently replacing what the
     * cashier is typing is how a transaction goes to the wrong customer.
     */
    public function recall(PosCart $held, User $user): PosCart
    {
        if ($held->status !== CartStatus::Held) {
            throw ValidationException::withMessages([
                'cart' => 'That cart is already at the register.',
            ]);
        }

        $companyId = $this->requireCompanyId();
        $registerId = $this->context->registerId();

        $working = PosCart::query()->working($companyId, $user->id, $registerId)->first();

        if ($working && $working->items()->exists()) {
            throw ValidationException::withMessages([
                'cart' => 'Finish or hold the current cart before recalling another one.',
            ]);
        }

        $held->forceFill([
            'status' => CartStatus::Active,
            'number' => null,
            'held_at' => null,
            'user_id' => $user->id,
            'register_id' => $registerId,
            'branch_id' => $this->context->branchId() ?? $held->branch_id,
            'warehouse_id' => $this->context->warehouseId() ?? $held->warehouse_id,
        ])->save();

        if ($working) {
            $working->forceDelete();
        }

        $this->audit->record('pos_cart.recall', 'pos_cart', $held->id, null, null, $held->company_id);

        return $held->fresh();
    }

    /**
     * Delete a parked draft for good. Drafts carry no stock, revenue or ledger
     * consequence, so removal is a plain soft delete.
     */
    public function deleteHeld(PosCart $cart, User $user): void
    {
        if ($cart->status !== CartStatus::Held) {
            throw ValidationException::withMessages([
                'cart' => 'Hold the cart before deleting it, or clear it from the register.',
            ]);
        }

        $cart->delete();

        $this->audit->record('pos_cart.delete', 'pos_cart', $cart->id, $cart->only(['number', 'status']), null, $cart->company_id);
    }

    /**
     * Recompute one line's derived money and store it.
     *
     * @return array{discount_amount: string, tax_amount: string, line_subtotal: string, line_total: string}
     */
    private function recalculateLine(PosCartItem $item): array
    {
        $computed = $this->calculation->calculateLines(collect([[
            'quantity' => (string) $item->quantity,
            'unit_price' => (string) $item->unit_price,
            'discount' => (string) $item->discount,
            'discount_type' => $item->discount_type ?? DiscountType::Amount,
            'tax_rate' => (string) $item->tax_rate,
            'tax_mode' => (string) $item->tax_mode,
        ]]))['lines'][0];

        $item->forceFill($computed)->save();

        return $computed;
    }

    /**
     * Resolve the product a line refers to: by product (+variant) id, or by a
     * scanned code across products, variants and secondary barcodes.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: Product, 1: ?ProductVariant}
     */
    private function resolveLineProduct(PosCart $cart, array $input): array
    {
        $companyId = $cart->company_id;

        if (! empty($input['product_id'])) {
            $product = Product::query()
                ->where('company_id', $companyId)
                ->where('is_sellable', true)
                ->where('is_active', true)
                ->find($input['product_id']);

            if (! $product) {
                throw ValidationException::withMessages([
                    'product_id' => 'That product is not available for sale.',
                ]);
            }

            $variant = null;

            if (! empty($input['product_variant_id'])) {
                $variant = ProductVariant::query()
                    ->where('product_id', $product->id)
                    ->where('is_active', true)
                    ->find($input['product_variant_id']);

                if (! $variant) {
                    throw ValidationException::withMessages([
                        'product_variant_id' => 'That variant does not belong to this product or is not active.',
                    ]);
                }
            }

            return [$product->load('defaultUnit'), $variant];
        }

        // Scanned path: one code, four places it can live, exact match only.
        // Sellability is checked after the match rather than in the query, so a
        // barred item is reported as barred instead of "not found" — the same
        // answer the till's scan endpoint gives, and the reason the cashier
        // needs: a disabled product is not a misread scanner.
        $code = trim((string) $input['barcode']);

        $product = Product::query()
            ->where('company_id', $companyId)
            ->where(fn ($q) => $q->where('barcode', $code)->orWhere('sku', $code))
            ->first();

        if ($product) {
            $this->ensureSellable($product, $code);

            return [$product->load('defaultUnit'), null];
        }

        $barcode = ProductBarcode::query()
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->first();

        if ($barcode) {
            $this->ensureSellable($barcode->product, $code);

            return [
                $barcode->product->load('defaultUnit'),
                $barcode->product_variant_id ? ProductVariant::find($barcode->product_variant_id) : null,
            ];
        }

        $variant = ProductVariant::query()
            ->where('company_id', $companyId)
            ->where(fn ($q) => $q->where('barcode', $code)->orWhere('sku', $code))
            ->first();

        if ($variant) {
            $this->ensureSellable($variant->product, $code);

            return [$variant->product->load('defaultUnit'), $variant];
        }

        throw ValidationException::withMessages([
            'barcode' => "Barcode {$code} was not found.",
        ]);
    }

    /**
     * A scanned code that resolves to something unsellable is refused by name.
     */
    private function ensureSellable(?Product $product, string $code): void
    {
        if ($product === null) {
            return;
        }

        if (! $product->is_sellable || ! $product->is_active) {
            throw ValidationException::withMessages([
                'barcode' => "Barcode {$code} belongs to {$product->name}, which cannot be sold.",
            ]);
        }
    }

    private function ensureWritable(PosCart $cart): void
    {
        if (! $cart->isEditable()) {
            throw ValidationException::withMessages([
                'cart' => 'This cart is on hold. Recall it before making changes.',
            ]);
        }
    }

    /**
     * A working cart belongs to the cashier at its register; held carts may be
     * recalled by anyone at the company, which recall() enforces separately.
     */
    private function ensureSameCashier(PosCart $cart, User $user): void
    {
        abort_unless($cart->user_id === $user->id, 403, 'This cart belongs to another cashier.');
    }

    private function requireCompanyId(): int
    {
        $companyId = $this->context->companyId();

        abort_if($companyId === null, 403, 'No active company context. Select a company first.');

        return $companyId;
    }
}
