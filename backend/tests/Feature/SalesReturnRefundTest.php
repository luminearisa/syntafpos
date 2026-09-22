<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\PaymentStatus;
use App\Enums\RefundMethod;
use App\Enums\RefundStatus;
use App\Enums\SaleReturnStatus;
use App\Enums\SaleStatus;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Tax;
use App\Models\Unit;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Returns, refunds and voids: the money that goes back after a sale.
 *
 * Three claims run through the file, one per document:
 *
 *  1. A return puts the goods back. It is written only against a sale that
 *     shipped stock, never for more than was sold, and its stock movement and its
 *     slip commit together in one transaction — a refused return leaves neither a
 *     row nor a quantity moved.
 *  2. A refund puts the money back. It is allocated to the tenders that settled,
 *     cannot exceed what is still refundable, and only writes a tender down when
 *     it completes — the threshold deciding whether a person has to sign first.
 *  3. A transaction is never deleted. Void, return and refund all leave the sale,
 *     its lines and its payments readable, and add an audit entry naming who did
 *     what and why.
 */
class SalesReturnRefundTest extends TestCase
{
    protected User $cashier;

    protected Unit $unit;

    protected Tax $ppn;

    protected Product $coffee;

    /**
     * The last response, so a refusal's message can be read after the status has
     * been asserted.
     */
    protected ?TestResponse $last = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashier = $this->authenticatedUser([
            'pos.view', 'pos.transact', 'pos.hold',
            'sales.view', 'sales.create', 'sales.complete', 'sales.cancel',
            'sales.return', 'sales.void',
            'refunds.view', 'refunds.create', 'refunds.approve', 'refunds.process',
        ]);

        $this->unit = Unit::factory()->for($this->company)->create(['code' => 'PCS']);
        $this->ppn = Tax::factory()->for($this->company)->create([
            'code' => 'PPN',
            'rate' => '11.0000',
            'type' => 'exclusive',
        ]);

        $this->coffee = $this->product([
            'sku' => 'COF-001',
            'barcode' => '8991000000011',
            'name' => 'Coffee House Blend',
            'selling_price' => '25000.0000',
        ]);
    }

    //
    // Fixtures
    //

    /** @param  array<string, mixed>  $attributes */
    protected function product(array $attributes = []): Product
    {
        return Product::factory()->for($this->company)->create(array_merge([
            'default_unit_id' => $this->unit->id,
            'tax_id' => $this->ppn->id,
            'is_sellable' => true,
            'is_active' => true,
            'track_inventory' => true,
            'selling_price' => '10000.0000',
            'cost_price' => '5000.0000',
        ], $attributes));
    }

    protected function stock(Product $product, string $quantity, string $averageCost = '5000.0000'): StockBalance
    {
        return StockBalance::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $product->id,
            'unit_id' => $this->unit->id,
            'on_hand' => $quantity,
            'reserved' => '0.000000',
            'average_cost' => $averageCost,
        ]);
    }

    /** @return array<string, string> */
    protected function headers(?User $user = null): array
    {
        $user ??= $this->cashier;

        return $this->authHeaders($user, array_filter([
            'company_id' => $user->companies()->pluck('companies.id')->first(),
            'branch_id' => $user->branches()->first()?->id,
            'warehouse_id' => $user->warehouses()->first()?->id,
            'register_id' => $user->registers()->first()?->id,
        ], fn ($value) => $value !== null));
    }

    /**
     * A second person in the same shop, holding only the listed permissions — the
     * smallest shape that proves a permission is doing the work.
     *
     * @param  list<string>  $permissions
     */
    protected function colleague(array $permissions): User
    {
        $user = User::factory()->create();
        $user->companies()->attach($this->company->id);
        $user->branches()->attach($this->branch->id);
        $user->warehouses()->attach($this->warehouse->id);
        $user->registers()->attach($this->register->id);

        $role = Role::create([
            'company_id' => $this->company->id,
            'name' => 'returns_'.uniqid(),
            'display_name' => 'Returns Role',
        ]);

        $role->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id')->all());
        $user->roles()->attach($role->id);
        $user->clearPermissionCache($this->company->id);

        return $user;
    }

    protected function cartId(?User $user = null): int
    {
        return (int) $this->getJson('/api/v1/pos/cart', $this->headers($user))
            ->assertOk()
            ->json('data.id');
    }

    /** @param  array<string, mixed>  $payload */
    protected function addToCart(int $cartId, array $payload, ?User $user = null): array
    {
        return $this->postJson("/api/v1/pos/cart/{$cartId}/items", $payload, $this->headers($user))
            ->assertCreated()
            ->json('data.cart');
    }

    /**
     * Ring up and settle a sale for the coffee, in full, returning the sale.
     *
     * @param  array<int, array<string, mixed>>|null  $payments
     * @return array<string, mixed>
     */
    protected function completedSale(string $quantity = '4', ?array $payments = null): array
    {
        $cart = $this->addToCart($this->cartId(), ['product_id' => $this->coffee->id, 'quantity' => $quantity]);

        $payments ??= [['channel' => 'cash', 'amount' => $cart['grand_total'], 'tendered' => $cart['grand_total']]];

        return $this->checkout($cart['id'], $payments)->assertCreated()->json('data');
    }

    /** @param  array<int, array<string, mixed>>  $payments */
    protected function checkout(int $cartId, array $payments, ?User $user = null): TestResponse
    {
        return $this->last = $this->postJson('/api/v1/sales', [
            'cart_id' => $cartId,
            'payments' => $payments,
        ], $this->headers($user));
    }

    /** @param  array<int, array<string, mixed>>  $items */
    protected function returnSale(int|array $sale, array $items, array $extra = [], ?User $user = null): TestResponse
    {
        $id = is_array($sale) ? $sale['id'] : $sale;

        return $this->last = $this->postJson("/api/v1/sales/{$id}/returns", array_merge([
            'items' => $items,
            'reason' => 'Customer changed their mind',
        ], $extra), $this->headers($user));
    }

    /** @param  array<string, mixed>  $payload */
    protected function raiseRefund(int|array $sale, array $payload, ?User $user = null): TestResponse
    {
        $id = is_array($sale) ? $sale['id'] : $sale;

        return $this->last = $this->postJson("/api/v1/sales/{$id}/refunds", array_merge([
            'method' => RefundMethod::Cash->value,
            'reason' => 'Item not wanted',
        ], $payload), $this->headers($user));
    }

    protected function setThreshold(string $threshold): void
    {
        $this->app->make(SettingsService::class)->set('refunds.approval_threshold', $threshold, $this->company->id);
    }

    protected function errorMessage(string $key): string
    {
        $errors = (array) $this->last?->json('errors');

        return implode(' | ', (array) ($errors[$key] ?? $this->last?->json("errors.{$key}")));
    }

    //
    // Sales returns — goods coming back
    //

    public function test_a_full_return_puts_everything_back_and_refunds_what_was_paid(): void
    {
        $this->stock($this->coffee, '50.000000');
        $sale = $this->completedSale('4');

        $response = $this->returnSale($sale, [
            ['sale_item_id' => $sale['items'][0]['id'], 'quantity' => '4'],
        ])->assertCreated();

        $return = $response->json('data');

        $this->assertSame(SaleReturnStatus::Completed->value, $return['status']);
        $this->assertSame($sale['grand_total'], $return['grand_total'], 'a full return gives back exactly what was paid');
        $this->assertSame('4.000000', $return['items'][0]['quantity']);
        // The cost basis the goods left at travels onto the slip for Phase 4.
        $this->assertSame('5000.0000', $return['items'][0]['unit_cost']);
        $this->assertSame('20000.0000', $return['cost_total']);

        // Stock went back through the ledger, on the original movement's value.
        $this->assertSame('50.000000', StockBalance::query()->where('product_id', $this->coffee->id)->sole()->on_hand);
        $this->assertSame(1, StockMovement::query()->where('movement_type', MovementType::SaleReturn->value)->count());

        // The sale itself is untouched: the return is the second half of its story.
        $saleRow = Sale::query()->findOrFail($sale['id']);
        $this->assertSame(SaleStatus::Completed, $saleRow->status);
        $this->assertSame('0.0000', $saleRow->refundedTotal());
    }

    public function test_a_partial_return_leaves_the_rest_returnable_until_it_is_not(): void
    {
        $this->stock($this->coffee, '50.000000');
        $sale = $this->completedSale('10');

        $this->returnSale($sale, [
            ['sale_item_id' => $sale['items'][0]['id'], 'quantity' => '3'],
        ])->assertCreated();

        $line = Sale::query()->findOrFail($sale['id'])->items()->sole();
        $this->assertSame('3.000000', $line->returnedQuantity());
        $this->assertSame('7.000000', $line->returnableQuantity());

        // Returning more than is left is refused, and the message says how much is.
        $this->returnSale($sale, [
            ['sale_item_id' => $sale['items'][0]['id'], 'quantity' => '8'],
        ])->assertStatus(422);
        $this->assertStringContainsString('only 7', $this->errorMessage('items'));

        // No half-written slip and no extra stock from the refusal.
        $this->assertSame(1, SaleReturn::query()->count());
        $this->assertSame('43.000000', StockBalance::query()->where('product_id', $this->coffee->id)->sole()->on_hand);

        // The remaining seven still come back, and then nothing does.
        $this->returnSale($sale, [
            ['sale_item_id' => $sale['items'][0]['id'], 'quantity' => '7'],
        ])->assertCreated();

        $this->returnSale($sale, [
            ['sale_item_id' => $sale['items'][0]['id'], 'quantity' => '1'],
        ])->assertStatus(422);
        $this->assertStringContainsString('already returned', $this->errorMessage('items'));

        $this->assertSame('50.000000', StockBalance::query()->where('product_id', $this->coffee->id)->sole()->on_hand);
    }

    public function test_repeating_a_line_in_one_return_is_caught_as_an_over_return(): void
    {
        $this->stock($this->coffee, '50.000000');
        $sale = $this->completedSale('10');

        // 6 + 6 against a sale of 10 is summed before it is judged, so the split
        // cannot smuggle a twelfth unit past the check.
        $this->returnSale($sale, [
            ['sale_item_id' => $sale['items'][0]['id'], 'quantity' => '6'],
            ['sale_item_id' => $sale['items'][0]['id'], 'quantity' => '6'],
        ])->assertStatus(422);

        $this->assertSame(0, SaleReturn::query()->count());
        $this->assertSame('40.000000', StockBalance::query()->where('product_id', $this->coffee->id)->sole()->on_hand);
    }

    public function test_a_return_needs_a_reason_and_a_sale_that_shipped_stock(): void
    {
        $this->stock($this->coffee, '50.000000');
        $sale = $this->completedSale('2');

        $this->returnSale($sale, [
            ['sale_item_id' => $sale['items'][0]['id'], 'quantity' => '1'],
        ], ['reason' => ''])->assertStatus(422);

        // An unpaid ticket never moved stock, so there is nothing to bring back.
        $cart = $this->addToCart($this->cartId(), ['product_id' => $this->coffee->id, 'quantity' => '1']);
        $pending = $this->checkout($cart['id'], [
            ['channel' => 'cash', 'amount' => '1000.0000'],
        ])->assertCreated()->json('data');

        $this->assertNull($pending['stock_posted_at']);

        $this->returnSale($pending, [
            ['sale_item_id' => $pending['items'][0]['id'], 'quantity' => '1'],
        ])->assertStatus(422);
    }

    public function test_a_return_is_not_allowed_against_a_cancelled_sale(): void
    {
        $this->stock($this->coffee, '50.000000');
        $sale = $this->completedSale('2');

        $this->last = $this->postJson("/api/v1/sales/{$sale['id']}/cancel", [], $this->headers())->assertOk();

        $this->returnSale($sale, [
            ['sale_item_id' => $sale['items'][0]['id'], 'quantity' => '1'],
        ])->assertStatus(422);
    }

    public function test_a_return_leaves_an_audit_trail(): void
    {
        $this->stock($this->coffee, '50.000000');
        $sale = $this->completedSale('2');

        $this->returnSale($sale, [
            ['sale_item_id' => $sale['items'][0]['id'], 'quantity' => '1'],
        ])->assertCreated();

        $entry = AuditLog::query()->where('action', 'sale_return.create')->latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertSame($this->cashier->id, $entry->user_id);
        $this->assertSame('Customer changed their mind', $entry->new_values['reason']);
    }

    //
    // Refunds — money going back
    //

    public function test_a_refund_under_the_threshold_is_approved_by_the_rule_and_writes_the_tender_down(): void
    {
        $this->setThreshold('200000');
        $this->stock($this->coffee, '50.000000');
        $sale = $this->completedSale('4');

        $refund = $this->raiseRefund($sale, ['amount' => '50000.0000'])
            ->assertCreated()
            ->json('data');

        $this->assertSame(RefundStatus::Approved->value, $refund['status']);
        $this->assertFalse($refund['approval_required']);
        $this->assertCount(1, $refund['allocations']);

        $completed = $this->postJson("/api/v1/refunds/{$refund['id']}/complete", [], $this->headers())
            ->assertOk()
            ->json('data');

        $this->assertSame(RefundStatus::Completed->value, $completed['status']);

        $payment = SalePayment::query()->where('sale_id', $sale['id'])->sole();
        $this->assertSame('50000.0000', (string) $payment->refunded_amount);
        $this->assertSame(PaymentStatus::PartiallyRefunded, $payment->status);

        // The sale was completed and stays completed; the refund is a second
        // document, and what is left to refund falls by exactly what went back.
        $saleRow = Sale::query()->findOrFail($sale['id']);
        $this->assertSame(SaleStatus::Completed, $saleRow->status);
        $this->assertSame('50000.0000', $saleRow->refundedTotal());
        $this->assertSame('61000.0000', $saleRow->refundableAmount());
    }

    public function test_a_refund_cannot_exceed_what_is_still_refundable(): void
    {
        $this->setThreshold('200000');
        $this->stock($this->coffee, '50.000000');
        $sale = $this->completedSale('4');

        $this->raiseRefund($sale, ['amount' => '200000.0000'])->assertStatus(422);
        $this->assertStringContainsString('refundable', $this->errorMessage('amount'));

        // Part of it goes back, and the next attempt is bounded by the remainder.
        $first = $this->raiseRefund($sale, ['amount' => '100000.0000'])->assertCreated()->json('data');
        $this->postJson("/api/v1/refunds/{$first['id']}/complete", [], $this->headers())->assertOk();

        $this->raiseRefund($sale, ['amount' => '50000.0000'])->assertStatus(422);

        $this->assertSame(1, Refund::query()->count());
    }

    public function test_a_refund_at_or_over_the_threshold_waits_for_an_approver(): void
    {
        $this->setThreshold('10000');
        $this->stock($this->coffee, '50.000000');
        $sale = $this->completedSale('4');

        $refund = $this->raiseRefund($sale, ['amount' => '40000.0000'])
            ->assertCreated()
            ->json('data');

        $this->assertSame(RefundStatus::Requested->value, $refund['status']);
        $this->assertTrue($refund['approval_required']);
        $this->assertSame('10000.0000', $refund['approval_threshold']);

        // Paying it out before it is approved is refused, and nothing is written.
        $this->postJson("/api/v1/refunds/{$refund['id']}/complete", [], $this->headers())->assertStatus(422);
        $this->assertSame('0.0000', (string) SalePayment::query()->where('sale_id', $sale['id'])->sole()->refunded_amount);

        // A manager signs, and then it pays out.
        $this->postJson("/api/v1/refunds/{$refund['id']}/approve", [], $this->headers())->assertOk();
        $this->postJson("/api/v1/refunds/{$refund['id']}/complete", [], $this->headers())->assertOk();

        $this->assertSame('40000.0000', (string) SalePayment::query()->where('sale_id', $sale['id'])->sole()->refunded_amount);
    }

    public function test_a_multi_payment_refund_is_split_across_the_tenders_that_settled(): void
    {
        $this->setThreshold('200000');
        $this->stock($this->coffee, '50.000000');

        $cart = $this->addToCart($this->cartId(), ['product_id' => $this->coffee->id, 'quantity' => '4']);
        $sale = $this->checkout($cart['id'], [
            ['channel' => 'cash', 'amount' => '60000.0000', 'tendered' => '60000.0000'],
            ['channel' => 'bank_transfer', 'amount' => '51000.0000', 'reference' => 'BB-1'],
        ])->assertCreated()->json('data');

        // Without explicit allocations the engine draws oldest-first.
        $refund = $this->raiseRefund($sale, ['amount' => '50000.0000'])->assertCreated()->json('data');

        $this->assertCount(1, $refund['allocations']);
        $this->assertSame('50000.0000', $refund['allocations'][0]['amount']);

        $this->postJson("/api/v1/refunds/{$refund['id']}/complete", [], $this->headers())->assertOk();

        $cash = SalePayment::query()->where('sale_id', $sale['id'])->orderBy('id')->first();
        $bank = SalePayment::query()->where('sale_id', $sale['id'])->orderByDesc('id')->first();

        $this->assertSame('50000.0000', (string) $cash->refunded_amount);
        $this->assertSame('0.0000', (string) $bank->refunded_amount);
    }

    public function test_explicit_allocations_must_belong_to_the_sale_and_add_up(): void
    {
        $this->setThreshold('200000');
        $this->stock($this->coffee, '50.000000');

        $cart = $this->addToCart($this->cartId(), ['product_id' => $this->coffee->id, 'quantity' => '4']);
        $sale = $this->checkout($cart['id'], [
            ['channel' => 'cash', 'amount' => '60000.0000', 'tendered' => '60000.0000'],
            ['channel' => 'bank_transfer', 'amount' => '51000.0000', 'reference' => 'BB-1'],
        ])->assertCreated()->json('data');

        $cash = SalePayment::query()->where('sale_id', $sale['id'])->orderBy('id')->first();
        $bank = SalePayment::query()->where('sale_id', $sale['id'])->orderByDesc('id')->first();

        // A split that does not add up is refused before anything is written.
        $this->raiseRefund($sale, [
            'amount' => '50000.0000',
            'allocations' => [
                ['sale_payment_id' => $cash->id, 'amount' => '20000.0000'],
                ['sale_payment_id' => $bank->id, 'amount' => '10000.0000'],
            ],
        ])->assertStatus(422);
        $this->assertSame(0, Refund::query()->count());

        // An explicit split the engine accepts lands exactly where it was pointed.
        $refund = $this->raiseRefund($sale, [
            'amount' => '50000.0000',
            'allocations' => [
                ['sale_payment_id' => $cash->id, 'amount' => '20000.0000'],
                ['sale_payment_id' => $bank->id, 'amount' => '30000.0000'],
            ],
        ])->assertCreated()->json('data');

        $this->assertCount(2, $refund['allocations']);

        $this->postJson("/api/v1/refunds/{$refund['id']}/complete", [], $this->headers())->assertOk();

        $this->assertSame('20000.0000', (string) $cash->fresh()->refunded_amount);
        $this->assertSame('30000.0000', (string) $bank->fresh()->refunded_amount);
    }

    public function test_a_refund_is_audited_at_each_step(): void
    {
        $this->setThreshold('200000');
        $this->stock($this->coffee, '50.000000');
        $sale = $this->completedSale('2');

        $refund = $this->raiseRefund($sale, ['amount' => '10000.0000'])->assertCreated()->json('data');
        $this->postJson("/api/v1/refunds/{$refund['id']}/complete", [], $this->headers())->assertOk();

        $this->assertNotNull(AuditLog::query()->where('action', 'refund.create')->latest('id')->first());
        $this->assertNotNull(AuditLog::query()->where('action', 'refund.complete')->latest('id')->first());
        $this->assertNotNull(AuditLog::query()->where('action', 'payment.refund')->latest('id')->first());
    }

    public function test_approving_a_refund_needs_the_approval_permission(): void
    {
        $this->setThreshold('10000');
        $this->stock($this->coffee, '50.000000');
        $sale = $this->completedSale('4');

        $refund = $this->raiseRefund($sale, ['amount' => '40000.0000'])->assertCreated()->json('data');

        $counter = $this->colleague(['refunds.view', 'refunds.create', 'refunds.process', 'sales.view']);

        // The person who raised it cannot sign their own exception.
        $this->postJson("/api/v1/refunds/{$refund['id']}/approve", [], $this->headers($counter))->assertForbidden();

        // A manager can.
        $this->postJson("/api/v1/refunds/{$refund['id']}/approve", [], $this->headers())->assertOk();
    }

    public function test_raising_a_refund_needs_the_refund_permission(): void
    {
        $this->setThreshold('200000');
        $this->stock($this->coffee, '50.000000');
        $sale = $this->completedSale('2');

        $floor = $this->colleague(['pos.view', 'pos.transact', 'sales.view']);

        $this->raiseRefund($sale, ['amount' => '1000.0000'], $floor)->assertForbidden();
    }

    //
    // Void — withdrawing an open ticket
    //

    public function test_voiding_an_open_ticket_keeps_every_row_and_records_the_reason(): void
    {
        $this->stock($this->coffee, '50.000000');
        $cart = $this->addToCart($this->cartId(), ['product_id' => $this->coffee->id, 'quantity' => '2']);

        // A short tender: the ticket is open and nothing has left the shelf.
        $sale = $this->checkout($cart['id'], [
            ['channel' => 'cash', 'amount' => '10000.0000'],
        ])->assertCreated()->json('data');

        $this->assertSame(SaleStatus::PartiallyPaid->value, $sale['status']);
        $this->assertNull($sale['stock_posted_at']);

        $voided = $this->postJson("/api/v1/sales/{$sale['id']}/void", [
            'reason' => 'Rang up the wrong table',
        ], $this->headers())->assertOk()->json('data');

        $this->assertSame(SaleStatus::Cancelled->value, $voided['status']);
        $this->assertSame('Rang up the wrong table', $voided['cancel_reason']);

        // Nothing was deleted: the sale and its tender are still readable.
        $this->assertNotNull(Sale::query()->find($sale['id']));
        $this->assertSame(1, SalePayment::query()->where('sale_id', $sale['id'])->count());
        $this->assertSame(PaymentStatus::Cancelled, SalePayment::query()->where('sale_id', $sale['id'])->sole()->status);

        $entry = AuditLog::query()->where('action', 'sale.void')->latest('id')->first();
        $this->assertNotNull($entry);
        $this->assertSame($this->cashier->id, $entry->user_id);
        $this->assertSame('Rang up the wrong table', $entry->new_values['reason']);
    }

    public function test_voiding_needs_a_reason_and_refuses_a_completed_sale(): void
    {
        $this->stock($this->coffee, '50.000000');
        $open = $this->checkout($this->addToCart($this->cartId(), ['product_id' => $this->coffee->id, 'quantity' => '1'])['id'], [
            ['channel' => 'cash', 'amount' => '1000.0000'],
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/sales/{$open['id']}/void", [], $this->headers())->assertStatus(422);

        // A completed sale is reversed with a return and a refund, never voided.
        $completed = $this->completedSale('2');

        $this->postJson("/api/v1/sales/{$completed['id']}/void", [
            'reason' => 'Trying it on',
        ], $this->headers())->assertStatus(422);

        $this->assertSame(SaleStatus::Completed, Sale::query()->findOrFail($completed['id'])->status);
    }

    public function test_voiding_needs_the_void_permission(): void
    {
        $this->stock($this->coffee, '50.000000');
        $cart = $this->addToCart($this->cartId(), ['product_id' => $this->coffee->id, 'quantity' => '1']);
        $sale = $this->checkout($cart['id'], [
            ['channel' => 'cash', 'amount' => '1000.0000'],
        ])->assertCreated()->json('data');

        // Cancel is not enough; void is its own authority.
        $supervisor = $this->colleague(['sales.view', 'sales.cancel']);

        $this->postJson("/api/v1/sales/{$sale['id']}/void", [
            'reason' => 'Still not allowed',
        ], $this->headers($supervisor))->assertForbidden();
    }

    //
    // Reading it back
    //

    public function test_returns_and_refunds_are_listed_on_the_sale_and_globally(): void
    {
        $this->setThreshold('200000');
        $this->stock($this->coffee, '50.000000');
        $sale = $this->completedSale('4');

        $return = $this->returnSale($sale, [
            ['sale_item_id' => $sale['items'][0]['id'], 'quantity' => '2'],
        ])->assertCreated()->json('data');

        $refund = $this->raiseRefund($sale, [
            'amount' => '20000.0000',
            'sale_return_id' => $return['id'],
        ])->assertCreated()->json('data');

        $this->assertSame($return['id'], $refund['sale_return_id']);

        $this->getJson("/api/v1/sales/{$sale['id']}/returns", $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson("/api/v1/sales/{$sale['id']}/refunds", $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/returns', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.0.number', $return['number']);

        $this->getJson('/api/v1/refunds', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.0.number', $refund['number']);

        // The sale document reports what has come back against it.
        $this->getJson("/api/v1/sales/{$sale['id']}", $this->headers())
            ->assertOk()
            ->assertJsonPath('data.returned_total', $return['grand_total'])
            ->assertJsonPath('data.refunded_total', '0.0000');
    }
}
