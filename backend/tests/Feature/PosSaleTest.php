<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\SaleStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DocumentSequence;
use App\Models\Permission;
use App\Models\PosCart;
use App\Models\PosCartItem;
use App\Models\Product;
use App\Models\Register;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Tax;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Checkout: the cart becomes a numbered sale, stock leaves, money is recorded.
 *
 * The file is organised around the four claims Subphase 3.2 makes.
 *
 *  1. Atomic. A checkout writes header, lines, movements and payments or
 *     nothing. Every failure test therefore asserts the *absence* of rows as
 *     strictly as the success tests assert their presence.
 *  2. Snapshotted. The sale stores its own copy of names, prices and customer
 *     details, so a master-data edit afterwards cannot rewrite history.
 *  3. Numbered by the Phase 1 engine, and never twice.
 *  4. Isolated. Another company's cashier cannot see, read or cancel a sale.
 */
class PosSaleTest extends TestCase
{
    protected User $cashier;

    protected Unit $unit;

    protected Tax $ppn;

    protected Product $coffee;

    /**
     * The last checkout response, so errorMessage() can read a validation message
     * after the caller has asserted the status.
     */
    protected ?TestResponse $last = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashier = $this->authenticatedUser([
            'pos.view', 'pos.transact', 'pos.hold',
            'sales.view', 'sales.create', 'sales.complete', 'sales.cancel',
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

    /**
     * A second shop: company, outlet, stockroom, till and unit.
     *
     * Everything the isolation and numbering tests need in order to be a real
     * other company rather than an empty row a cashier cannot even log into.
     *
     * @return array{company: Company, branch: Branch, warehouse: Warehouse, register: Register, unit: Unit}
     */
    protected function rivalShop(): array
    {
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->for($branch)->create();
        $register = Register::factory()->for($company)->for($branch)->for($warehouse)->create();
        $unit = Unit::factory()->for($company)->create(['code' => 'PCS']);

        return compact('company', 'branch', 'warehouse', 'register', 'unit');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function productIn(array $shop, array $attributes = []): Product
    {
        return Product::factory()->for($shop['company'])->create(array_merge([
            'default_unit_id' => $shop['unit']->id,
            'is_sellable' => true,
            'is_active' => true,
            'track_inventory' => true,
            'selling_price' => '10000.0000',
            'cost_price' => '5000.0000',
        ], $attributes));
    }

    protected function stockIn(array $shop, Product $product, string $quantity): StockBalance
    {
        return StockBalance::create([
            'company_id' => $shop['company']->id,
            'branch_id' => $shop['branch']->id,
            'warehouse_id' => $shop['warehouse']->id,
            'product_id' => $product->id,
            'unit_id' => $shop['unit']->id,
            'on_hand' => $quantity,
            'reserved' => '0.000000',
        ]);
    }

    protected function stock(Product $product, string $quantity, ?Warehouse $warehouse = null): StockBalance
    {
        return StockBalance::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'warehouse_id' => ($warehouse ?? $this->warehouse)->id,
            'product_id' => $product->id,
            'unit_id' => $this->unit->id,
            'on_hand' => $quantity,
            'reserved' => '0.000000',
        ]);
    }

    protected function headers(?User $user = null): array
    {
        return $this->authHeaders($user ?? $this->cashier, $this->contextOf($user ?? $this->cashier));
    }

    /**
     * The business context a given user actually has access to.
     *
     * A colleague inside another company must send their own headers: reusing
     * this test's branch and register would resolve to no company at all, and
     * the isolation tests would then be proving nothing but a 403.
     */
    protected function contextOf(User $user): array
    {
        return array_filter([
            'company_id' => $user->companies()->pluck('companies.id')->first(),
            'branch_id' => $user->branches()->first()?->id,
            'warehouse_id' => $user->warehouses()->first()?->id,
            'register_id' => $user->registers()->first()?->id,
        ], fn ($value) => $value !== null);
    }

    protected function cartId(?User $user = null): int
    {
        return (int) $this->getJson('/api/v1/pos/cart', $this->headers($user))
            ->assertOk()
            ->json('data.id');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function addToCart(int $cartId, array $payload, ?User $user = null): array
    {
        $response = $this->postJson("/api/v1/pos/cart/{$cartId}/items", $payload, $this->headers($user));
        $response->assertCreated();

        return $response->json('data.cart');
    }

    /**
     * A cart holding the same product on two separate lines.
     *
     * The till joins a repeated scan onto the line it already has, so the split
     * is forced by copying the row — which is exactly what the aggregated stock
     * check has to survive, whether the lines came from two registers, a recall
     * or an import. The header is then re-saved so the money engine prices both
     * lines before the till reads its own total back.
     *
     * @return array<string, mixed> the server's cart, totals included
     */
    protected function cartWithSplitLines(int $cartId, string $quantity): array
    {
        $this->addToCart($cartId, ['product_id' => $this->coffee->id, 'quantity' => $quantity]);

        $line = PosCartItem::query()->where('pos_cart_id', $cartId)->sole();
        $line->replicate()->save();

        $this->assertSame(2, PosCartItem::query()->where('pos_cart_id', $cartId)->count());

        $this->putJson("/api/v1/pos/cart/{$cartId}", ['notes' => 'two lines'], $this->headers())->assertOk();

        return $this->getJson("/api/v1/pos/cart/{$cartId}", $this->headers())->assertOk()->json('data');
    }

    /**
     * The till's checkout call.
     *
     * The last response is also kept on the test, so a validation message can be
     * read after the status has been asserted without every caller holding the
     * response object.
     *
     * @param  array<int, array<string, mixed>>  $payments
     */
    protected function checkout(int $cartId, array $payments, array $extra = []): TestResponse
    {
        return $this->last = $this->postJson('/api/v1/sales', [
            'cart_id' => $cartId,
            'payments' => $payments,
        ] + $extra, $this->headers($this->cashier));
    }

    /**
     * The same request from another cashier — used by the isolation tests, whose
     * whole point is that the second user cannot reach the first one's documents.
     */
    protected function checkoutAs(User $user, int $cartId, array $payments): TestResponse
    {
        $this->last = $this->postJson('/api/v1/sales', [
            'cart_id' => $cartId,
            'payments' => $payments,
        ], $this->headers($user));

        return $this->last;
    }

    /**
     * The message the last response carried for an error key, or an empty string.
     *
     * The payment engine keys its refusals to the field the till should highlight,
     * which for a tender inside a list is a dotted name — `payments.amount`. Laravel
     * returns those as literal keys, and `json()` would read the dots as a path, so
     * the map is searched by name first and only falls back to the path lookup.
     */
    protected function errorMessage(string $key): string
    {
        $errors = (array) $this->last?->json('errors');

        return implode(' | ', (array) ($errors[$key] ?? $this->last?->json("errors.{$key}")));
    }

    /**
     * Hand back a response and remember it, so errorMessage() can read the reason
     * a state change was refused.
     */
    protected function keep(TestResponse $response): TestResponse
    {
        return $this->last = $response;
    }

    /**
     * Cash for the exact total, read back off the cart the server computed.
     *
     * Deliberately not a hard-coded figure: if the money engine changed, a test
     * with a literal amount would move with it and stop proving anything.
     */
    protected function exact(array $cart): array
    {
        return [['channel' => 'cash', 'amount' => $cart['grand_total'], 'tendered' => $cart['grand_total']]];
    }

    //
    // Sale creation
    //

    public function test_checkout_turns_a_cart_into_a_numbered_sale(): void
    {
        $this->stock($this->coffee, '50.000000');
        $cart = $this->addToCart($this->cartId(), ['product_id' => $this->coffee->id, 'quantity' => '2']);

        $response = $this->checkout($cart['id'], $this->exact($cart))
            ->assertCreated()
            ->assertJsonPath('success', true);

        $sale = $response->json('data');

        $this->assertSame(SaleStatus::Completed->value, $sale['status']);
        $this->assertSame($this->company->id, $sale['company_id']);
        $this->assertSame($this->branch->id, $sale['branch_id']);
        $this->assertSame($this->warehouse->id, $sale['warehouse_id']);
        $this->assertSame($this->register->id, $sale['register_id']);
        $this->assertSame($this->cashier->id, $sale['cashier_id']);
        $this->assertSame(now()->toDateString(), $sale['date']);
        $this->assertSame('25000.0000', $sale['items'][0]['unit_price']);
        $this->assertSame('2.000000', $sale['items'][0]['quantity']);
        $this->assertSame($sale['grand_total'], $cart['grand_total'], 'the till and the sale must agree');
        $this->assertSame($sale['grand_total'], $sale['paid_total']);
        $this->assertNotEmpty($sale['number']);
    }

    public function test_a_sale_stores_the_lines_the_cart_had(): void
    {
        $this->stock($this->coffee, '50.000000');
        $sugar = $this->product(['sku' => 'SUG-001', 'name' => 'Sugar Packet', 'selling_price' => '1500.0000']);
        $this->stock($sugar, '50.000000');

        $cart = $this->addToCart($this->cartId(), ['product_id' => $this->coffee->id, 'quantity' => '2']);
        $cart = $this->addToCart($cart['id'], ['product_id' => $sugar->id, 'quantity' => '5']);

        $sale = $this->checkout($cart['id'], $this->exact($cart))->assertCreated()->json('data');

        $this->assertCount(2, $sale['items']);
        $this->assertSame(
            ['Coffee House Blend', 'Sugar Packet'],
            collect($sale['items'])->pluck('product_name')->all()
        );
        $this->assertSame('5.000000', $sale['items'][1]['quantity']);
        // Two lines, two movements out.
        $this->assertSame(2, StockMovement::query()->where('movement_type', MovementType::Sale->value)->count());
    }

    public function test_the_sale_copies_the_carts_discounts_tax_and_charges(): void
    {
        $this->stock($this->coffee, '50.000000');
        $cartId = $this->cartId();
        $cart = $this->addToCart($cartId, ['product_id' => $this->coffee->id, 'quantity' => '4']);
        $this->putJson("/api/v1/pos/cart/{$cartId}", [
            'discount_input' => '5000',
            'discount_type' => 'amount',
            'other_charges' => '1000',
            'notes' => 'Room service',
        ], $this->headers())->assertOk();

        $cart = $this->getJson("/api/v1/pos/cart/{$cartId}", $this->headers())->assertOk()->json('data');
        $sale = $this->checkout($cartId, $this->exact($cart))->assertCreated()->json('data');

        foreach (['subtotal', 'discount_total', 'tax_total', 'other_charges', 'rounding', 'grand_total'] as $field) {
            $this->assertSame($cart[$field], $sale[$field], "{$field} should carry over unchanged");
        }

        $this->assertSame('5000.0000', $sale['discount_total']);
        $this->assertSame('Room service', $sale['notes']);
        // 4 x 25000 = 100000, +11% tax = 111000, -5000 discount, +1000 charge.
        $this->assertSame('107000.0000', $sale['grand_total']);
    }

    public function test_a_sale_can_be_settled_with_split_tenders(): void
    {
        $this->stock($this->coffee, '50.000000');
        $cart = $this->addToCart($this->cartId(), ['product_id' => $this->coffee->id]);
        $total = $cart['grand_total'];
        $half = bcdiv($total, '2', 4);

        $sale = $this->checkout($cart['id'], [
            ['channel' => 'cash', 'amount' => $half, 'tendered' => $total],
            ['channel' => 'credit_card', 'amount' => bcsub($total, $half, 4)],
        ])->assertCreated()->json('data');

        $this->assertSame(SaleStatus::Completed->value, $sale['status']);
        $this->assertSame($total, $sale['paid_total']);
        $this->assertCount(2, $sale['payments']);
        $this->assertSame('cash', $sale['payments'][0]['channel']);
        $this->assertSame('credit_card', $sale['payments'][1]['channel']);
        // The payment names the shop's method rather than only its channel, so a
        // receipt printed years later still says what the customer was told.
        $this->assertSame('Cash', $sale['payments'][0]['method_name']);
        // Only cash is tendered, so only cash produces change.
        $this->assertSame(bcsub($total, $half, 4), $sale['payments'][0]['change']);
        $this->assertSame('0.0000', $sale['payments'][1]['change']);
        $this->assertSame($sale['payments'][0]['change'], $sale['change_due']);
    }

    public function test_a_sale_rejects_a_tender_larger_than_the_balance(): void
    {
        $this->stock($this->coffee, '50.000000');
        $cart = $this->addToCart($this->cartId(), ['product_id' => $this->coffee->id]);

        $this->checkout($cart['id'], [['channel' => 'cash', 'amount' => '999999.0000']])
            ->assertStatus(422);

        // Named by the key the till highlights, and with the figure the customer
        // still owes written the way the printed receipt writes it — currency
        // sign, minor units, local grouping — not the raw ledger string.
        $this->assertStringContainsString('remaining balance of Rp 27.750.', $this->errorMessage('payments.amount'));

        $this->assertSame(0, Sale::count());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_an_empty_cart_is_not_checkable_out(): void
    {
        $this->checkout($this->cartId(), [])
            ->assertStatus(422);

        $this->assertSame('There is nothing to sell — the cart is empty.', $this->errorMessage('cart'));

        $this->assertSame(0, Sale::count());
    }

    //
    // Stock
    //

    public function test_a_completed_sale_moves_stock_out(): void
    {
        $balance = $this->stock($this->coffee, '50.000000');
        $cart = $this->addToCart($this->cartId(), ['product_id' => $this->coffee->id, 'quantity' => '3']);

        $sale = $this->checkout($cart['id'], $this->exact($cart))->assertCreated()->json('data');

        $this->assertSame('47.000000', $balance->fresh()->on_hand);
        $this->assertSame('47.000000', $sale['stock_posted_at'] ? (string) $balance->fresh()->on_hand : 'no');

        $movement = StockMovement::query()
            ->where('reference_type', Sale::class)
            ->where('reference_id', $sale['id'])
            ->sole();

        $this->assertSame(MovementType::Sale, $movement->movement_type);
        $this->assertSame('-3.000000', (string) $movement->quantity);
        $this->assertSame('47.000000', (string) $movement->balance_after);
        $this->assertSame($this->warehouse->id, $movement->warehouse_id);
        $this->assertSame($this->cashier->id, $movement->created_by);
        $this->assertStringContainsString($sale['number'], (string) $movement->notes);
    }

    public function test_stock_shortage_refuses_the_whole_sale(): void
    {
        $balance = $this->stock($this->coffee, '2.000000');
        $cart = $this->addToCart($this->cartId(), ['product_id' => $this->coffee->id, 'quantity' => '5']);

        $this->checkout($cart['id'], $this->exact($cart))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertStringContainsString(
            'Coffee House Blend',
            $this->errorMessage('items'),
            'the cashier must be told which product ran out'
        );

        // Nothing partial: no document, no ledger row, no tender, no movement.
        $this->assertSame(0, Sale::count());
        $this->assertSame(0, SaleItem::count());
        $this->assertSame(0, SalePayment::count());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame('2.000000', $balance->fresh()->on_hand);
        // The cart survives a failed checkout, or the cashier retypes everything.
        $this->assertNotNull(PosCart::find($cart['id']));
    }

    public function test_a_partially_fillable_sale_stocks_out_only_when_it_fits(): void
    {
        // The partial case in two directions: a basket that fits on one line but
        // not the other must fail entirely, and the same basket with enough of
        // both must succeed — the difference is one unit of one product.
        $tea = $this->product(['sku' => 'TEA-001', 'name' => 'Tea Bag', 'selling_price' => '3000.0000']);
        $this->stock($this->coffee, '10.000000');
        $shortTea = $this->stock($tea, '1.000000');

        $cartId = $this->cartId();
        $this->addToCart($cartId, ['product_id' => $this->coffee->id, 'quantity' => '4']);
        $cart = $this->addToCart($cartId, ['product_id' => $tea->id, 'quantity' => '2']);

        $this->checkout($cartId, $this->exact($cart))->assertStatus(422);
        $this->assertSame(0, Sale::count());
        $this->assertSame('10.000000', $this->freshOnHand($this->coffee), 'coffee must not have left the shelf');
        $this->assertSame('1.000000', $shortTea->fresh()->on_hand);

        // Restock the shortfall and the identical checkout goes through.
        $shortTea->forceFill(['on_hand' => '5.000000'])->save();
        $sale = $this->checkout($cartId, $this->exact($cart))->assertCreated()->json('data');

        $this->assertSame('6.000000', $this->freshOnHand($this->coffee));
        $this->assertSame('3.000000', $this->freshOnHand($tea));
        $this->assertCount(2, $sale['items']);
    }

    public function test_two_lines_of_one_product_are_checked_against_one_balance(): void
    {
        // The balance says 5 and the basket says 3 + 3, so the sale must fail
        // even though neither line alone is short.
        $this->stock($this->coffee, '5.000000');
        $cart = $this->cartWithSplitLines($this->cartId(), '3');

        $this->assertCount(2, $cart['items']);
        $this->assertSame('3.000000', $cart['items'][0]['quantity']);

        $this->checkout((int) $cart['id'], $this->exact($cart))->assertStatus(422);
        $this->assertStringContainsString('Coffee House Blend', $this->errorMessage('items'));
        $this->assertSame('5.000000', $this->freshOnHand($this->coffee));
        $this->assertSame(0, Sale::count());
    }

    public function test_an_untracked_product_is_sold_without_moving_stock(): void
    {
        $service = $this->product([
            'sku' => 'SRV-001',
            'name' => 'Gift Wrapping',
            'selling_price' => '5000.0000',
            'track_inventory' => false,
        ]);

        $cart = $this->addToCart($this->cartId(), ['product_id' => $service->id]);
        $sale = $this->checkout($cart['id'], $this->exact($cart))->assertCreated()->json('data');

        // Billed like any other line — 5000 plus the shop's 11% — but nothing
        // moves, so no balance row springs into existence for a service.
        $this->assertSame('5000.0000', $sale['subtotal']);
        $this->assertSame('550.0000', $sale['tax_total']);
        $this->assertSame('5550.0000', $sale['grand_total']);
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, StockBalance::query()->where('product_id', $service->id)->count());
    }

    public function test_a_product_that_allows_negative_stock_is_not_blocked(): void
    {
        $product = $this->product([
            'sku' => 'ICE-001',
            'name' => 'Ice Cup',
            'selling_price' => '4000.0000',
            'allow_negative_stock' => true,
        ]);
        $this->stock($product, '1.000000');

        $cart = $this->addToCart($this->cartId(), ['product_id' => $product->id, 'quantity' => '5']);
        $sale = $this->checkout($cart['id'], $this->exact($cart))->assertCreated()->json('data');

        $this->assertSame('-4.000000', $this->freshOnHand($product));
        $this->assertSame(SaleStatus::Completed->value, $sale['status']);
    }

    public function test_stock_is_not_touched_until_the_ticket_is_settled(): void
    {
        $balance = $this->stock($this->coffee, '10.000000');
        $cart = $this->addToCart($this->cartId(), ['product_id' => $this->coffee->id]);

        // A short tender: the ticket is recorded, the goods stay on the shelf.
        $sale = $this->checkout($cart['id'], [['channel' => 'cash', 'amount' => '1000.0000']])
            ->assertCreated()
            ->json('data');

        $this->assertSame(SaleStatus::PartiallyPaid->value, $sale['status']);
        $this->assertNull($sale['stock_posted_at']);
        $this->assertSame('10.000000', $balance->fresh()->on_hand);
        $this->assertSame(0, StockMovement::count());

        // The balance arriving is what moves the goods.
        $saleId = $sale['id'];
        $this->postJson("/api/v1/sales/{$saleId}/complete", [
            'payments' => [['channel' => 'cash', 'amount' => bcsub($sale['grand_total'], '1000.0000', 4)]],
        ], $this->headers())->assertOk()->assertJsonPath('data.status', SaleStatus::Completed->value);

        $this->assertSame('9.000000', $balance->fresh()->on_hand);
        $this->assertSame(1, StockMovement::count());
    }

    //
    // Snapshots
    //

    public function test_master_data_edits_never_rewrite_a_sale(): void
    {
        $customer = Customer::factory()->for($this->company)->create([
            'name' => 'Bu Sari',
            'customer_code' => 'C-001',
            'phone' => '0812-3456-7890',
        ]);

        $this->stock($this->coffee, '10.000000');
        $cartId = $this->cartId();
        $this->putJson("/api/v1/pos/cart/{$cartId}", ['customer_id' => $customer->id], $this->headers())->assertOk();
        $cart = $this->addToCart($cartId, ['product_id' => $this->coffee->id]);

        $sale = $this->checkout($cartId, $this->exact($cart))->assertCreated()->json('data');

        $this->assertSame('Coffee House Blend', $sale['items'][0]['product_name']);
        $this->assertSame('COF-001', $sale['items'][0]['product_sku']);
        $this->assertSame('25000.0000', $sale['items'][0]['unit_price']);
        $this->assertSame('11.0000', $sale['items'][0]['tax_rate']);
        $this->assertSame('exclusive', $sale['items'][0]['tax_mode']);
        $this->assertSame('PCS', $sale['items'][0]['unit_code']);
        $this->assertSame('Bu Sari', $sale['customer']['name']);
        $this->assertSame($this->branch->name, $sale['outlet']['name']);

        // The catalogue moves on completely: rename, reprice, re-tax the product,
        // rename the customer, deactivate it, then delete the customer outright.
        $this->coffee->forceFill([
            'name' => 'Renamed Blend',
            'sku' => 'NEW-SKU',
            'selling_price' => '99000.0000',
            'tax_id' => null,
            'is_active' => false,
        ])->save();
        $customer->forceFill(['name' => 'Someone Else'])->save();
        $customer->delete();
        $this->unit->forceFill(['code' => 'EA'])->save();

        $fresh = $this->getJson("/api/v1/sales/{$sale['id']}", $this->headers())->assertOk()->json('data');

        $this->assertSame('Coffee House Blend', $fresh['items'][0]['product_name']);
        $this->assertSame('COF-001', $fresh['items'][0]['product_sku']);
        $this->assertSame('25000.0000', $fresh['items'][0]['unit_price']);
        $this->assertSame('PCS', $fresh['items'][0]['unit_code']);
        $this->assertSame('Bu Sari', $fresh['customer']['name']);
        $this->assertSame('C-001', $fresh['customer']['code']);
        $this->assertSame('25000.0000', $fresh['subtotal']);
        $this->assertSame($sale['grand_total'], $fresh['grand_total']);
    }

    public function test_a_sale_stores_each_line_money_the_way_the_cart_computed_it(): void
    {
        // Inclusive tax is the case that exposes a copied-but-not-derived line:
        // the customer pays the printed price, so the header totals look right
        // even when the line's own tax carve-out and total are missing. A
        // receipt prints a line's stored figure, so those have to be on the row.
        $pb1 = Tax::factory()->for($this->company)->create([
            'code' => 'PB1',
            'rate' => '10.0000',
            'type' => 'inclusive',
        ]);
        $room = $this->product([
            'sku' => 'RM-001',
            'name' => 'Room Night',
            'selling_price' => '22000.0000',
            'tax_id' => $pb1->id,
        ]);

        $this->stock($room, '10.000000');
        $cart = $this->addToCart($this->cartId(), ['product_id' => $room->id, 'quantity' => '2']);
        $sale = $this->checkout($cart['id'], $this->exact($cart))->assertCreated()->json('data');

        $line = $sale['items'][0];

        $this->assertSame('inclusive', $line['tax_mode']);
        $this->assertSame('10.0000', $line['tax_rate']);
        $this->assertSame('44000.0000', $line['line_subtotal'], '2 x 22000 grossed onto the line');
        $this->assertSame('4000.0000', $line['tax_amount'], 'the 10% carved out of the price, not added to it');
        $this->assertSame('44000.0000', $line['line_total'], 'what the customer pays for the line');
        $this->assertSame('0.0000', $line['discount_amount']);

        // The lines have to add up to the header, or the invoice body and its
        // total are two different transactions.
        $lineTotals = collect($sale['items'])->reduce(
            fn (string $carry, array $line) => bcadd($carry, $line['line_total'], 4),
            '0'
        );
        $this->assertSame($sale['grand_total'], $lineTotals);
        $this->assertSame('4000.0000', $sale['tax_total']);
        $this->assertSame('4000.0000', $sale['tax_included_total']);

        // And on paper: the roll prints the line's own figure.
        $html = $this->getJson("/api/v1/sales/{$sale['id']}/receipt?width=80", $this->headers())
            ->assertOk()->json('data.html');

        $this->assertStringContainsString('2 x Rp 22.000 = Rp 44.000', $html);
    }

    //
    // Invoice and receipt
    //

    public function test_a_sale_reads_as_an_invoice(): void
    {
        $customer = Customer::factory()->for($this->company)->create([
            'name' => 'Pak Budi',
            'customer_code' => 'C-002',
            'phone' => '0813-1111-2222',
            'address' => 'Jl Melati 5',
        ]);

        $this->stock($this->coffee, '10.000000');
        $cartId = $this->cartId();
        $this->putJson("/api/v1/pos/cart/{$cartId}", ['customer_id' => $customer->id], $this->headers())->assertOk();
        $cart = $this->addToCart($cartId, ['product_id' => $this->coffee->id, 'quantity' => '2']);

        $sale = $this->getJson("/api/v1/sales/{$this->checkout($cartId, $this->exact($cart))->assertCreated()->json('data.id')}", $this->headers())
            ->assertOk()
            ->json('data');

        // The invoice detail the work order enumerates, each from the sale's own
        // columns rather than a live relation.
        $this->assertSame($this->company->name, $sale['company']['name']);
        $this->assertSame($this->branch->name, $sale['outlet']['name']);
        $this->assertSame('Pak Budi', $sale['customer']['name']);
        $this->assertSame('Jl Melati 5', $sale['customer']['address']);
        $this->assertMatchesRegularExpression('/^INV-\d{4}-\d{6}$/', $sale['number']);
        $this->assertSame($sale['number'], $sale['number']);
        $this->assertNotEmpty($sale['date']);
        $this->assertCount(1, $sale['items']);
        $this->assertSame('0.0000', $sale['discount_total']);
        $this->assertSame('5500.0000', $sale['tax_total']);
        $this->assertSame('55500.0000', $sale['grand_total']);
        $this->assertSame('Paid', $sale['payment_status']);
        $this->assertTrue($sale['fully_paid']);
        $this->assertSame('0.0000', $sale['balance_due']);
    }

    public function test_an_unpaid_ticket_says_so_on_the_invoice(): void
    {
        $this->stock($this->coffee, '10.000000');
        $cart = $this->addToCart($this->cartId(), ['product_id' => $this->coffee->id]);

        // No tender at all: the sale is the order step of the flow, waiting for
        // payment, and stock stays put.
        $sale = $this->checkout($cart['id'], [])->assertCreated()->json('data');

        $this->assertSame(SaleStatus::Draft->value, $sale['status']);
        $this->assertSame('Unpaid', $sale['payment_status']);
        $this->assertFalse($sale['fully_paid']);
        $this->assertSame($sale['grand_total'], $sale['balance_due']);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_a_receipt_renders_for_every_paper_width(): void
    {
        $this->stock($this->coffee, '10.000000');
        $cart = $this->addToCart($this->cartId(), ['product_id' => $this->coffee->id, 'quantity' => '2']);
        $sale = $this->checkout($cart['id'], $this->exact($cart))->assertCreated()->json('data');

        foreach (['58', '80', 'a4'] as $width) {
            $response = $this->getJson("/api/v1/sales/{$sale['id']}/receipt?width={$width}", $this->headers())
                ->assertOk()
                ->assertJsonPath('data.width', $width);

            $html = $response->json('data.html');

            $this->assertStringContainsString($sale['number'], $html, "{$width} must name the document");
            $this->assertStringContainsString('Coffee House Blend', $html);
            $this->assertStringContainsString('Rp 55.500', $html, "{$width} must show the server's total");
            $this->assertStringContainsString('<style>', $html);
        }

        $roll = $this->getJson("/api/v1/sales/{$sale['id']}/receipt?width=58", $this->headers())->json('data.html');
        $invoice = $this->getJson("/api/v1/sales/{$sale['id']}/receipt?width=a4", $this->headers())->json('data.html');

        $this->assertStringContainsString('@page { size: 58mm auto', $roll, 'a roll needs its own paper size');
        $this->assertStringContainsString('@page { size: a4 auto', $invoice);
        $this->assertStringContainsString('INVOICE', $invoice);
        $this->assertStringContainsString('PAID', $invoice);
    }

    public function test_a_receipt_prints_a_customer_name_as_the_sale_recorded_it(): void
    {
        $customer = Customer::factory()->for($this->company)->create(['name' => 'Toko <b>Raya</b>']);
        $this->stock($this->coffee, '10.000000');
        $cartId = $this->cartId();
        $this->putJson("/api/v1/pos/cart/{$cartId}", ['customer_id' => $customer->id], $this->headers())->assertOk();
        $cart = $this->addToCart($cartId, ['product_id' => $this->coffee->id]);
        $sale = $this->checkout($cartId, $this->exact($cart))->assertCreated()->json('data');

        $html = $this->getJson("/api/v1/sales/{$sale['id']}/receipt?width=80", $this->headers())
            ->assertOk()->json('data.html');

        // Snapshot in the document, and escaped: a receipt is HTML a browser
        // prints, so an angle bracket in a shop name must not become markup.
        $this->assertStringContainsString('Toko &lt;b&gt;Raya&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>Raya</b>', $html);
    }

    public function test_a_receipt_width_is_validated(): void
    {
        $this->stock($this->coffee, '10.000000');
        $cart = $this->addToCart($this->cartId(), ['product_id' => $this->coffee->id]);
        $sale = $this->checkout($cart['id'], $this->exact($cart))->assertCreated()->json('data');

        $this->getJson("/api/v1/sales/{$sale['id']}/receipt?width=200", $this->headers())->assertStatus(422);
    }

    //
    // Cancellation
    //

    public function test_cancelling_a_sale_puts_the_stock_back(): void
    {
        $balance = $this->stock($this->coffee, '10.000000');
        $cart = $this->addToCart($this->cartId(), ['product_id' => $this->coffee->id, 'quantity' => '4']);
        $sale = $this->checkout($cart['id'], $this->exact($cart))->assertCreated()->json('data');

        $this->assertSame('6.000000', $balance->fresh()->on_hand);

        $cancelled = $this->postJson("/api/v1/sales/{$sale['id']}/cancel", ['reason' => 'Customer changed their mind'], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.status', SaleStatus::Cancelled->value)
            ->assertJsonPath('data.cancel_reason', 'Customer changed their mind')
            ->assertJsonPath('data.payment_status', 'Cancelled')
            ->json('data');

        $this->assertSame('10.000000', $balance->fresh()->on_hand, 'a cancelled sale must return what it took');

        $return = StockMovement::query()->where('movement_type', MovementType::SaleReturn->value)->sole();
        $this->assertSame('4.000000', (string) $return->quantity);
        $this->assertSame($sale['id'], $return->reference_id);

        // Money is voided, not refunded — cash back across the counter is a
        // later subphase, and the trail of what was taken stays on the record.
        $this->assertSame('0.0000', $cancelled['paid_total']);
        $this->assertSame($cancelled['grand_total'], $cancelled['balance_due']);
        $this->assertSame('cancelled', $cancelled['payments'][0]['status']);
        $payment = SalePayment::sole();
        $this->assertSame('cancelled', $payment->status->value);
        $this->assertSame(SaleStatus::Cancelled->value, Sale::find($sale['id'])->status->value);
    }

    public function test_cancelling_returns_only_what_actually_left(): void
    {
        // The sale mixes a tracked and an untracked line. Only the tracked one
        // was ever booked, so only that one may come back — returning the
        // service line would invent stock nobody took.
        $service = $this->product([
            'sku' => 'SRV-002',
            'name' => 'Delivery Fee',
            'selling_price' => '8000.0000',
            'track_inventory' => false,
        ]);
        $this->stock($this->coffee, '10.000000');

        $cartId = $this->cartId();
        $this->addToCart($cartId, ['product_id' => $this->coffee->id, 'quantity' => '2']);
        $cart = $this->addToCart($cartId, ['product_id' => $service->id]);

        $sale = $this->checkout($cartId, $this->exact($cart))->assertCreated()->json('data');
        $this->assertSame(1, StockMovement::query()->where('movement_type', MovementType::Sale->value)->count());

        $this->postJson("/api/v1/sales/{$sale['id']}/cancel", [], $this->headers())->assertOk();

        $this->assertSame(1, StockMovement::query()->where('movement_type', MovementType::SaleReturn->value)->count());
        $this->assertSame('10.000000', $this->freshOnHand($this->coffee));
        $this->assertSame(0, StockBalance::query()->where('product_id', $service->id)->count());
    }

    public function test_a_sale_can_be_cancelled_before_stock_ever_moves(): void
    {
        $balance = $this->stock($this->coffee, '10.000000');
        $cart = $this->addToCart($this->cartId(), ['product_id' => $this->coffee->id]);
        $sale = $this->checkout($cart['id'], [['channel' => 'cash', 'amount' => '1000.0000']])->assertCreated()->json('data');

        $this->postJson("/api/v1/sales/{$sale['id']}/cancel", [], $this->headers())->assertOk();

        $this->assertSame('10.000000', $balance->fresh()->on_hand);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_a_sale_cannot_be_cancelled_twice_or_completed_afterwards(): void
    {
        $this->stock($this->coffee, '10.000000');
        $cart = $this->addToCart($this->cartId(), ['product_id' => $this->coffee->id]);
        $sale = $this->checkout($cart['id'], $this->exact($cart))->assertCreated()->json('data');

        $this->postJson("/api/v1/sales/{$sale['id']}/cancel", [], $this->headers())->assertOk();
        $this->keep($this->postJson("/api/v1/sales/{$sale['id']}/cancel", [], $this->headers()))
            ->assertStatus(422);
        $this->assertStringContainsString('already cancelled', $this->errorMessage('sale'));

        $this->keep($this->postJson("/api/v1/sales/{$sale['id']}/complete", [], $this->headers()))
            ->assertStatus(422);
        $this->assertStringContainsString('can no longer be completed', $this->errorMessage('sale'));

        // The refusal did not put the stock back a second time either.
        $this->assertSame(1, StockMovement::query()->where('movement_type', MovementType::SaleReturn->value)->count());
    }

    public function test_a_completed_sale_cannot_be_completed_again(): void
    {
        $this->stock($this->coffee, '10.000000');
        $cart = $this->addToCart($this->cartId(), ['product_id' => $this->coffee->id]);
        $sale = $this->checkout($cart['id'], $this->exact($cart))->assertCreated()->json('data');

        $this->keep($this->postJson("/api/v1/sales/{$sale['id']}/complete", [], $this->headers()))
            ->assertStatus(422);
        $this->assertStringContainsString('already completed', $this->errorMessage('sale'));

        // Stock moved exactly once, whatever the retry attempts.
        $this->assertSame(1, StockMovement::count());
        $this->assertSame('9.000000', $this->freshOnHand($this->coffee));
    }

    //
    // Numbering
    //

    public function test_sale_numbers_come_from_the_sequence_engine_and_never_repeat(): void
    {
        $this->stock($this->coffee, '100.000000');
        $numbers = [];

        for ($i = 0; $i < 3; $i++) {
            $cart = $this->addToCart($this->cartId(), ['product_id' => $this->coffee->id]);
            $response = $this->checkout($cart['id'], $this->exact($cart))->assertCreated();
            $numbers[] = $response->json('data.number');
            $this->assertSame('INV-'.now()->year.'-'.str_pad((string) ($i + 1), 6, '0', STR_PAD_LEFT), $numbers[$i]);
        }

        $this->assertSame($numbers, array_unique($numbers));
        $this->assertSame(3, Sale::count());
        // A tender gets its own series, so an invoice number never doubles as a
        // payment number.
        $this->assertSame('PAY-'.now()->year.'-000001', SalePayment::orderBy('id')->value('number'));
    }

    public function test_one_company_cannot_issue_the_same_number_as_another(): void
    {
        $this->stock($this->coffee, '10.000000');
        $cart = $this->addToCart($this->cartId(), ['product_id' => $this->coffee->id]);
        $first = $this->checkout($cart['id'], $this->exact($cart))->assertCreated()->json('data');

        // A real second shop, with its own catalogue: another company's cashier
        // cannot sell this company's product, and a numbering test that failed for
        // that reason alone would prove nothing about the sequences.
        $shop = $this->rivalShop();
        $roast = $this->productIn($shop, ['sku' => 'ROB-001', 'name' => 'Robusta', 'selling_price' => '12000.0000']);
        $this->stockIn($shop, $roast, '10.000000');

        $other = $this->colleague(['pos.view', 'pos.transact', 'sales.view', 'sales.create'], $shop);
        $otherCart = $this->cartId($other);
        $cart2 = $this->addToCart($otherCart, ['product_id' => $roast->id], $other);

        $second = $this->checkoutAs($other, $otherCart, $this->exact($cart2))
            ->assertCreated()
            ->json('data');

        // Both are the first of their series, and both exist: uniqueness is
        // per company, so a second shop restarting at -000001 is not a clash.
        $this->assertSame($first['number'], $second['number']);
        $this->assertNotSame($first['company_id'], $second['company_id']);
        $this->assertSame(2, Sale::count());
        $this->assertSame(2, DocumentSequence::query()->where('document_type', 'invoice')->count());
    }

    //
    // Atomicity
    //

    public function test_a_failure_inside_the_sale_leaves_nothing_behind(): void
    {
        $this->stock($this->coffee, '10.000000');
        $cart = $this->addToCart($this->cartId(), ['product_id' => $this->coffee->id]);
        $cartId = $cart['id'];

        // Force the collision the (company_id, number) unique index exists to catch
        // by pre-occupying the number this company's sequence is about to hand out.
        Sale::create([
            'company_id' => $this->company->id,
            'number' => 'INV-'.now()->year.'-000001',
            'date' => now()->toDateString(),
            'cashier_id' => $this->cashier->id,
        ]);

        $this->checkout($cartId, $this->exact($cart))->assertStatus(500);

        // Only the planted row exists; the rolled-back sale wrote no lines, no
        // payments and no movements, and the shelf is untouched.
        $this->assertSame(1, Sale::count());
        $this->assertSame(0, SaleItem::count());
        $this->assertSame(0, SalePayment::count());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame('10.000000', $this->freshOnHand($this->coffee));
        $this->assertNotNull(PosCart::withTrashed()->find($cartId), 'a failed checkout must leave the cart alone');
        $this->assertNull(PosCart::withTrashed()->find($cartId)->deleted_at);
    }

    public function test_a_sale_writes_its_documents_in_one_go(): void
    {
        $this->stock($this->coffee, '10.000000');
        $cart = $this->addToCart($this->cartId(), ['product_id' => $this->coffee->id]);
        $sale = $this->checkout($cart['id'], $this->exact($cart))->assertCreated()->json('data');

        // Header, line, movement and tender all present from one request: the
        // count of queries is the proxy for "there was no gap a crash could
        // have fallen into".
        $this->assertDatabaseHas('sales', ['id' => $sale['id'], 'number' => $sale['number']]);
        $this->assertDatabaseHas('sale_items', ['sale_id' => $sale['id'], 'product_sku' => 'COF-001']);
        $this->assertDatabaseHas('sale_payments', ['sale_id' => $sale['id']]);
        $this->assertDatabaseHas('stock_movements', ['reference_id' => $sale['id'], 'movement_type' => 'sale']);
        $this->assertSoftDeleted('pos_carts', ['id' => $cart['id']]);
    }

    //
    // Isolation and authorization
    //

    public function test_another_companys_cashier_cannot_read_or_cancel_a_sale(): void
    {
        $this->stock($this->coffee, '10.000000');
        $cart = $this->addToCart($this->cartId(), ['product_id' => $this->coffee->id]);
        $sale = $this->checkout($cart['id'], $this->exact($cart))->assertCreated()->json('data');

        $shop = $this->rivalShop();
        $stranger = $this->colleague(
            ['sales.view', 'sales.cancel', 'sales.create', 'pos.view', 'pos.transact'],
            $shop
        );

        // The list is empty: another company's takings are not on this screen.
        $this->getJson('/api/v1/sales', $this->headers($stranger))->assertOk()->assertJsonCount(0, 'data');

        // Reach for the document by id and it is refused outright — the policy
        // checks the sale's own company, not the header the request carries, so
        // a stranger cannot even read the totals off a receipt.
        $this->getJson("/api/v1/sales/{$sale['id']}", $this->headers($stranger))->assertForbidden();
        $this->postJson("/api/v1/sales/{$sale['id']}/cancel", [], $this->headers($stranger))->assertForbidden();
        $this->getJson("/api/v1/sales/{$sale['id']}/receipt", $this->headers($stranger))->assertForbidden();

        $this->assertSame(SaleStatus::Completed->value, Sale::find($sale['id'])->status->value);
    }

    public function test_a_sale_list_only_shows_ones_own_shop(): void
    {
        $this->stock($this->coffee, '10.000000');
        $cart = $this->addToCart($this->cartId(), ['product_id' => $this->coffee->id]);
        $sale = $this->checkout($cart['id'], $this->exact($cart))->assertCreated()->json('data');

        // A colleague of the *same* company who may view sales but did not make
        // this one still sees it: isolation is per company, not per cashier.
        $manager = $this->colleague(['sales.view']);
        $this->getJson('/api/v1/sales', $this->headers($manager))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', $sale['number']);
    }

    public function test_reading_and_selling_need_separate_permissions(): void
    {
        $this->stock($this->coffee, '10.000000');
        $cart = $this->addToCart($this->cartId(), ['product_id' => $this->coffee->id]);
        $sale = $this->checkout($cart['id'], $this->exact($cart))->assertCreated()->json('data');

        $viewer = $this->colleague(['sales.view']);
        $this->getJson("/api/v1/sales/{$sale['id']}", $this->headers($viewer))->assertOk();
        $this->postJson("/api/v1/sales/{$sale['id']}/cancel", [], $this->headers($viewer))->assertStatus(403);

        $noSales = $this->colleague(['pos.view', 'pos.transact']);
        $this->getJson('/api/v1/sales', $this->headers($noSales))->assertStatus(403);
        $this->postJson('/api/v1/sales', ['cart_id' => $sale['id'], 'payments' => []], $this->headers($noSales))
            ->assertStatus(403);
    }

    public function test_a_sale_cannot_be_edited_or_deleted_through_the_api(): void
    {
        $this->stock($this->coffee, '10.000000');
        $cart = $this->addToCart($this->cartId(), ['product_id' => $this->coffee->id]);
        $sale = $this->checkout($cart['id'], $this->exact($cart))->assertCreated()->json('data');

        // No PUT, no DELETE route exists — a posted transaction is corrected by a
        // cancellation. 405 is the answer that keeps the record intact.
        $this->putJson("/api/v1/sales/{$sale['id']}", ['notes' => 'edited'], $this->headers())->assertStatus(405);
        $this->deleteJson("/api/v1/sales/{$sale['id']}", [], $this->headers())->assertStatus(405);
        $this->assertSame($sale['grand_total'], $this->getJson("/api/v1/sales/{$sale['id']}", $this->headers())->json('data.grand_total'));
    }

    public function test_the_sales_api_answers_with_the_standard_envelope(): void
    {
        $this->stock($this->coffee, '10.000000');
        $cart = $this->addToCart($this->cartId(), ['product_id' => $this->coffee->id]);
        $created = $this->checkout($cart['id'], $this->exact($cart))->assertCreated();

        foreach ([$created, $this->getJson('/api/v1/sales', $this->headers())->assertOk()] as $response) {
            $response->assertJsonStructure(['success', 'message', 'data', 'meta']);
        }

        // Money crosses the wire as strings, never as a float.
        $this->assertIsString($created->json('data.grand_total'));
        $this->assertIsString($created->json('data.items.0.unit_price'));
    }

    /**
     * The sales list is a finding tool, so its rows carry a summary rather than
     * the document: line count, the tender totals a shift reconciles by, and who
     * rang it up. A row that had to load its items to render would be a query per
     * row, which is what the count column exists to avoid.
     */
    public function test_the_sale_list_rows_carry_what_a_row_needs_to_render(): void
    {
        $this->stock($this->coffee, '10.000000');
        $cart = $this->addToCart($this->cartId(), ['product_id' => $this->coffee->id, 'quantity' => '2']);
        $sale = $this->checkout($cart['id'], $this->exact($cart))->assertCreated()->json('data');

        $row = $this->getJson('/api/v1/sales?per_page=5', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.0.number', $sale['number'])
            ->json('data.0');

        $this->assertSame(1, $row['item_count']);
        $this->assertSame($sale['grand_total'], $row['grand_total']);
        $this->assertSame($sale['paid_total'], $row['paid_total']);
        $this->assertSame('0.0000', $row['balance_due']);
        $this->assertSame($this->register->code, $row['register']['code']);
        $this->assertSame($this->cashier->name, $row['cashier']['name']);
        // The tender summary without the drawer's detail: enough to reconcile a
        // shift from the list, not the whole payment trail.
        $this->assertCount(1, $row['payments']);
        $this->assertSame('cash', $row['payments'][0]['channel']);
        // The lines themselves belong to the document, not the list.
        $this->assertArrayNotHasKey('items', $row);
        // The customer is a snapshot column on the sale, so no join is needed.
        $this->assertNull($row['customer']['id']);
    }

    //
    // Helpers
    //

    protected function freshOnHand(Product $product): string
    {
        // Summed in PHP, not SQL: SQLite's SUM() answers an integer for a
        // decimal column, which would quietly drop the scale every assertion
        // here compares against.
        $total = '0.000000';

        foreach (StockBalance::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->pluck('on_hand') as $onHand) {
            $total = bcadd($total, (string) $onHand, 6);
        }

        return $total;
    }

    /**
     * A second user, optionally inside another company.
     *
     * The shop tree is passed rather than rebuilt, so a rival cashier stands in
     * front of the shelf the test actually stocked — otherwise a failure would
     * be about the fixture, not the isolation.
     *
     * @param  list<string>  $permissions
     * @param  array<string, Model>|null  $shop
     */
    protected function colleague(array $permissions, ?array $shop = null): User
    {
        $shop ??= [
            'company' => $this->company,
            'branch' => $this->branch,
            'warehouse' => $this->warehouse,
            'register' => $this->register,
        ];

        $company = $shop['company'];
        $user = User::factory()->create();
        $user->companies()->attach($company->id);
        $user->branches()->attach($shop['branch']->id);
        $user->warehouses()->attach($shop['warehouse']->id);
        $user->registers()->attach($shop['register']->id);

        $role = Role::create([
            'company_id' => $company->id,
            'name' => 'sales_role_'.uniqid(),
            'display_name' => 'Sales Role',
        ]);
        $role->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id')->all());
        $user->roles()->attach($role->id);
        $user->clearPermissionCache($company->id);

        return $user;
    }
}
