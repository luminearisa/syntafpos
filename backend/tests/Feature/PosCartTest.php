<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\PosCart;
use App\Models\PosCartItem;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductPrice;
use App\Models\ProductVariant;
use App\Models\Register;
use App\Models\Role;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Tax;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The working cart: add, edit, discount, hold, recall.
 *
 * Two invariants run through this file. A cart is a draft — it must never touch
 * stock, the ledger or revenue — and every money figure is the server's, so the
 * totals a cashier reads are the totals checkout will charge.
 */
class PosCartTest extends TestCase
{
    protected User $cashier;

    protected Unit $unit;

    protected Tax $ppn;

    protected Product $coffee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashier = $this->authenticatedUser(['pos.view', 'pos.transact', 'pos.hold']);
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

    protected function context(): array
    {
        return [
            'branch_id' => $this->branch->id,
            'warehouse_id' => $this->warehouse->id,
            'register_id' => $this->register->id,
        ];
    }

    protected function headers(): array
    {
        return $this->authHeaders($this->cashier, $this->context());
    }

    /**
     * The till opens itself: this is what a page load does.
     */
    protected function openCart(): array
    {
        $response = $this->getJson('/api/v1/pos/cart', $this->headers());
        $response->assertOk();

        return $response->json('data');
    }

    protected function cartId(): int
    {
        return (int) $this->openCart()['id'];
    }

    /**
     * @return array<string, mixed> the cart as the server recomputed it
     */
    protected function addProduct(int $cartId, array $payload): array
    {
        $response = $this->postJson("/api/v1/pos/cart/{$cartId}/items", $payload, $this->headers());
        $response->assertCreated();
        $this->assertTrue($response->json('success'));

        return $response->json('data.cart');
    }

    //
    // Opening and persistence
    //

    public function test_opening_the_till_gives_back_one_empty_cart(): void
    {
        $cart = $this->openCart();

        $this->assertSame('active', $cart['status']);
        $this->assertSame($this->cashier->id, $cart['user_id']);
        $this->assertSame($this->company->id, $cart['company_id']);
        $this->assertSame($this->register->id, $cart['register_id']);
        $this->assertSame('0.0000', $cart['grand_total']);
        $this->assertSame([], $cart['items']);
        // No recall code until the cart is actually parked.
        $this->assertNull($cart['number']);
    }

    public function test_a_reload_resumes_the_same_cart_with_its_items(): void
    {
        $cart = $this->openCart();
        $this->addProduct($cart['id'], ['product_id' => $this->coffee->id]);

        $again = $this->openCart();

        $this->assertSame($cart['id'], $again['id'], 'a reload must not orphan the scan already made');
        $this->assertCount(1, $again['items']);
        $this->assertSame('1.000000', $again['items'][0]['quantity']);
    }

    public function test_two_registers_of_one_cashier_are_two_carts(): void
    {
        $other = Register::factory()->for($this->company)->for($this->branch)->for($this->warehouse)->create(['code' => 'REG-B']);
        $this->cashier->registers()->attach($other->id);

        $here = $this->openCart();
        $there = $this->getJson('/api/v1/pos/cart', $this->authHeaders($this->cashier, [
            'warehouse_id' => $this->warehouse->id,
            'register_id' => $other->id,
        ]))->assertOk()->json('data');

        $this->assertNotSame($here['id'], $there['id']);
    }

    //
    // Adding lines
    //

    public function test_a_picked_product_lands_on_the_cart_with_its_snapshot(): void
    {
        $cart = $this->addProduct($this->cartId(), ['product_id' => $this->coffee->id]);

        $line = $cart['items'][0];
        $this->assertSame($this->coffee->id, $line['product_id']);
        $this->assertSame('Coffee House Blend', $line['product_name']);
        $this->assertSame('COF-001', $line['product_sku']);
        $this->assertSame('8991000000011', $line['barcode']);
        $this->assertSame('PCS', $line['unit_code']);
        $this->assertSame('1.000000', $line['quantity']);
        $this->assertSame('25000.0000', $line['unit_price']);
        $this->assertSame('product', $line['price_source']);
        $this->assertSame('11.0000', $line['tax_rate']);
        $this->assertSame('exclusive', $line['tax_mode']);
    }

    public function test_a_second_scan_of_the_same_code_joins_the_line(): void
    {
        $cartId = $this->cartId();
        $this->addProduct($cartId, ['product_id' => $this->coffee->id]);
        $cart = $this->addProduct($cartId, ['product_id' => $this->coffee->id, 'quantity' => '2']);

        $this->assertCount(1, $cart['items'], 'a repeat scan is a quantity, not a new line');
        $this->assertSame('3.000000', $cart['items'][0]['quantity']);
        $this->assertSame('75000.0000', $cart['items'][0]['line_subtotal']);
    }

    public function test_a_scanned_barcode_adds_the_product_it_points_at(): void
    {
        $cart = $this->addProduct($this->cartId(), ['barcode' => '8991000000011']);

        $this->assertSame($this->coffee->id, $cart['items'][0]['product_id']);
    }

    public function test_a_secondary_or_variant_code_adds_through_the_cart_too(): void
    {
        ProductBarcode::create([
            'company_id' => $this->company->id,
            'product_id' => $this->coffee->id,
            'code' => 'BOX-OF-24',
            'type' => 'internal',
        ]);
        $variant = ProductVariant::factory()->for($this->coffee)->create([
            'company_id' => $this->company->id,
            'sku' => 'COF-DECAF',
            'barcode' => '8991000000042',
            'name' => 'Decaf',
            'selling_price' => '27000.0000',
        ]);

        $cart = $this->addProduct($this->cartId(), ['barcode' => 'BOX-OF-24']);
        $this->assertSame($this->coffee->id, $cart['items'][0]['product_id']);
        $this->assertSame('8991000000011', $cart['items'][0]['barcode'], 'the product barcode stays on the line, not the box code');

        $cart = $this->addProduct($cart['id'], ['barcode' => '8991000000042']);
        $variantLine = collect($cart['items'])->firstWhere('product_variant_id', $variant->id);
        $this->assertNotNull($variantLine, 'the variant scan must land as its own line');
        $this->assertSame('Decaf', $variantLine['variant_name']);
        $this->assertSame('COF-DECAF', $variantLine['product_sku']);
        $this->assertSame('27000.0000', $variantLine['unit_price']);
    }

    public function test_an_unknown_barcode_is_refused_with_the_code_named(): void
    {
        $cartId = $this->cartId();

        $this->postJson("/api/v1/pos/cart/{$cartId}/items", ['barcode' => '0000111122223'], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.barcode.0', 'Barcode 0000111122223 was not found.');

        $this->assertSame(0, PosCartItem::count());
    }

    public function test_a_barcode_on_an_unsellable_product_says_so_rather_than_not_found(): void
    {
        $staff = $this->product(['name' => 'Staff Mug', 'barcode' => '8991000000777', 'is_sellable' => false]);

        $this->postJson("/api/v1/pos/cart/{$this->cartId()}/items", ['barcode' => $staff->barcode], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('errors.barcode.0', 'Barcode 8991000000777 belongs to Staff Mug, which cannot be sold.');
    }

    public function test_a_product_from_another_company_cannot_be_rung_up(): void
    {
        $theirs = Company::factory()->create();
        $foreign = Product::factory()->for($theirs)->create(['is_sellable' => true, 'is_active' => true, 'barcode' => '9990000000015']);

        $response = $this->postJson("/api/v1/pos/cart/{$this->cartId()}/items", ['product_id' => $foreign->id], $this->headers());
        $response->assertStatus(422);

        $this->assertStringContainsString('cannot be sold', $response->json('errors.product_id.0'));
        $this->assertSame(0, PosCartItem::count());
    }

    public function test_a_line_carries_a_note_and_the_note_survives_a_recompute(): void
    {
        $cart = $this->addProduct($this->cartId(), [
            'product_id' => $this->coffee->id,
            'notes' => 'no ice, extra shot',
        ]);

        $this->assertSame('no ice, extra shot', $cart['items'][0]['notes']);

        $updated = $this->putJson("/api/v1/pos/cart/{$cart['id']}/items/{$cart['items'][0]['id']}", [
            'quantity' => '2',
        ], $this->headers())->assertOk()->json('data.cart');

        $this->assertSame('no ice, extra shot', $updated['items'][0]['notes']);
    }

    //
    // Editing lines
    //

    public function test_quantity_is_typed_not_clicked(): void
    {
        $cart = $this->addProduct($this->cartId(), ['product_id' => $this->coffee->id]);
        $lineId = $cart['items'][0]['id'];

        $cart = $this->putJson("/api/v1/pos/cart/{$cart['id']}/items/{$lineId}", ['quantity' => '7.5'], $this->headers())
            ->assertOk()->json('data.cart');

        $this->assertSame('7.500000', $cart['items'][0]['quantity']);
        $this->assertSame('187500.0000', $cart['items'][0]['line_subtotal']);
        // 187500 x 11% = 20625, total 208125.
        $this->assertSame('20625.0000', $cart['items'][0]['tax_amount']);
        $this->assertSame('208125.0000', $cart['grand_total']);
    }

    public function test_a_quantity_of_zero_drops_the_line(): void
    {
        $cart = $this->addProduct($this->cartId(), ['product_id' => $this->coffee->id]);
        $lineId = $cart['items'][0]['id'];

        $response = $this->putJson("/api/v1/pos/cart/{$cart['id']}/items/{$lineId}", ['quantity' => '0'], $this->headers());
        $response->assertOk();
        $this->assertNull($response->json('data.item'));
        $this->assertSame([], $response->json('data.cart.items'));
        $this->assertSame('0.0000', $response->json('data.cart.grand_total'));
    }

    public function test_a_line_can_be_removed_and_the_cart_cleared(): void
    {
        $cartId = $this->cartId();
        $mug = $this->product(['name' => 'Ceramic Mug', 'sku' => 'MUG-1']);
        $this->addProduct($cartId, ['product_id' => $this->coffee->id]);
        $cart = $this->addProduct($cartId, ['product_id' => $mug->id]);
        $this->assertCount(2, $cart['items']);

        $this->deleteJson("/api/v1/pos/cart/{$cartId}/items/{$cart['items'][0]['id']}", [], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.item_count', 1);

        $this->deleteJson("/api/v1/pos/cart/{$cartId}/items", [], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.item_count', 0)
            ->assertJsonPath('data.grand_total', '0.0000');

        // Clearing empties the till but keeps the cashier where they are.
        $this->assertSame($cartId, $this->openCart()['id']);
    }

    public function test_a_line_of_another_cart_is_not_reachable(): void
    {
        $mine = $this->addProduct($this->cartId(), ['product_id' => $this->coffee->id]);
        $lineId = $mine['items'][0]['id'];

        $otherCashier = $this->colleague(['pos.view', 'pos.transact', 'pos.hold']);
        $theirCart = $this->getJson('/api/v1/pos/cart', $this->authHeaders($otherCashier, $this->context()))
            ->assertOk()->json('data');

        $this->deleteJson("/api/v1/pos/cart/{$theirCart['id']}/items/{$lineId}", [], $this->authHeaders($otherCashier, $this->context()))
            ->assertNotFound();
    }

    //
    // Pricing
    //

    public function test_a_lines_price_comes_from_the_engine_not_the_till(): void
    {
        $list = PriceList::factory()->for($this->company)->create([
            'name' => 'Standard Retail',
            'status' => 'active',
            'is_default' => true,
            'start_date' => null,
            'end_date' => null,
        ]);
        ProductPrice::create([
            'price_list_id' => $list->id,
            'product_id' => $this->coffee->id,
            'price_type' => 'retail',
            'price' => '22000.0000',
        ]);

        $line = $this->addProduct($this->cartId(), ['product_id' => $this->coffee->id])['items'][0];

        $this->assertSame('22000.0000', $line['unit_price']);
        $this->assertSame('default_list', $line['price_source']);
    }

    public function test_attaching_a_customer_reprices_the_line_at_their_tier(): void
    {
        $customer = Customer::factory()->for($this->company)->create(['name' => 'Toko Sembako']);
        $list = PriceList::factory()->for($this->company)->create([
            'name' => 'Reseller Rate',
            'status' => 'active',
            'is_default' => false,
            'start_date' => null,
            'end_date' => null,
        ]);
        $customer->forceFill(['price_list_id' => $list->id])->save();
        ProductPrice::create([
            'price_list_id' => $list->id,
            'product_id' => $this->coffee->id,
            'price_type' => 'reseller',
            'price' => '19000.0000',
        ]);

        $cartId = $this->cartId();
        $this->putJson("/api/v1/pos/cart/{$cartId}", ['customer_id' => $customer->id], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.customer.name', 'Toko Sembako');

        $line = $this->addProduct($cartId, ['product_id' => $this->coffee->id])['items'][0];

        $this->assertSame('19000.0000', $line['unit_price']);
        $this->assertSame('price_list', $line['price_source']);
    }

    public function test_the_cart_reports_the_customer_the_selector_shows(): void
    {
        $list = PriceList::factory()->for($this->company)->create(['name' => 'Cafe Rate', 'is_default' => false]);
        $customer = Customer::factory()->for($this->company)->create([
            'name' => 'Cafe Nusantara',
            'customer_code' => 'CN-001',
            'phone' => '0811-2222-3333',
            'price_list_id' => $list->id,
        ]);

        $cart = $this->putJson("/api/v1/pos/cart/{$this->cartId()}", ['customer_id' => $customer->id], $this->headers())
            ->assertOk()->json('data');

        $this->assertSame('Cafe Nusantara', $cart['customer']['name']);
        $this->assertSame('CN-001', $cart['customer']['customer_code']);
        $this->assertSame('0811-2222-3333', $cart['customer']['phone']);
        $this->assertSame($list->id, $cart['customer']['price_list_id']);
        // The selector names the tier, so the cashier can explain the price.
        $this->assertSame('Cafe Rate', $cart['customer']['price_list']['name']);

        $this->putJson("/api/v1/pos/cart/{$cart['id']}", ['clear_customer' => true], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.customer_id', null);
    }

    //
    // Money
    //

    public function test_a_line_total_is_subtotal_minus_discount_plus_tax(): void
    {
        $cart = $this->addProduct($this->cartId(), [
            'product_id' => $this->coffee->id,
            'quantity' => '4',
        ]);
        $lineId = $cart['items'][0]['id'];

        // 4 x 25000 = 100000 gross, 10% off = 10000, net 90000, tax 9900.
        $line = $this->putJson("/api/v1/pos/cart/{$cart['id']}/items/{$lineId}", [
            'discount' => '10',
            'discount_type' => 'percent',
        ], $this->headers())->assertOk()->json('data.cart.items')[0];

        $this->assertSame('100000.0000', $line['line_subtotal']);
        $this->assertSame('10000.0000', $line['discount_amount']);
        $this->assertSame('9900.0000', $line['tax_amount']);
        $this->assertSame('99900.0000', $line['line_total']);
    }

    public function test_a_flat_line_discount_is_not_rescaled_by_a_later_edit(): void
    {
        $cart = $this->addProduct($this->cartId(), ['product_id' => $this->coffee->id]);
        $lineId = $cart['items'][0]['id'];

        $this->putJson("/api/v1/pos/cart/{$cart['id']}/items/{$lineId}", [
            'discount' => '5000',
            'discount_type' => 'amount',
        ], $this->headers())->assertOk();

        // The entry stays "5000 amount"; only the computed figures move with qty.
        $line = $this->putJson("/api/v1/pos/cart/{$cart['id']}/items/{$lineId}", [
            'quantity' => '3',
        ], $this->headers())->assertOk()->json('data.cart.items')[0];

        $this->assertSame('5000.0000', $line['discount']);
        $this->assertSame('amount', $line['discount_type']);
        $this->assertSame('5000.0000', $line['discount_amount']);
        $this->assertSame('75000.0000', $line['line_subtotal']);
        $this->assertSame('7700.0000', $line['tax_amount']);
    }

    public function test_a_line_discount_cannot_exceed_the_line(): void
    {
        $cart = $this->addProduct($this->cartId(), ['product_id' => $this->coffee->id]);

        $line = $this->putJson("/api/v1/pos/cart/{$cart['id']}/items/{$cart['items'][0]['id']}", [
            'discount' => '999999',
        ], $this->headers())->assertOk()->json('data.cart.items')[0];

        $this->assertSame('25000.0000', $line['discount_amount']);
        $this->assertSame('0.0000', $line['tax_amount']);
        $this->assertSame('0.0000', $line['line_total']);
    }

    public function test_an_inclusive_tax_line_shows_the_tax_without_adding_it(): void
    {
        $inclusive = Tax::factory()->for($this->company)->create([
            'code' => 'PB1',
            'rate' => '10.0000',
            'type' => 'inclusive',
        ]);
        $otel = $this->product(['name' => 'Room Night', 'sku' => 'ROOM-1', 'selling_price' => '550000.0000', 'tax_id' => $inclusive->id]);

        $line = $this->addProduct($this->cartId(), ['product_id' => $otel->id])['items'][0];

        $this->assertSame('inclusive', $line['tax_mode']);
        $this->assertSame('550000.0000', $line['line_subtotal']);
        // 550000 contains 10% tax: net 500000, tax 50000.
        $this->assertSame('50000.0000', $line['tax_amount']);
        $this->assertSame('550000.0000', $line['line_total']);
    }

    public function test_inclusive_and_exclusive_tax_add_up_differently(): void
    {
        $inclusive = Tax::factory()->for($this->company)->create([
            'code' => 'PB1',
            'rate' => '10.0000',
            'type' => 'inclusive',
        ]);
        $otel = $this->product(['name' => 'Room Night', 'sku' => 'ROOM-1', 'selling_price' => '550000.0000', 'tax_id' => $inclusive->id]);

        $cartId = $this->cartId();
        $this->addProduct($cartId, ['product_id' => $otel->id]);
        // One exclusive line on top: 10 x 1000, 11% = 1100.
        $this->addProduct($cartId, ['product_id' => $this->product([
            'name' => 'Notepad',
            'sku' => 'PAD-1',
            'selling_price' => '1000.0000',
            'tax_id' => null,
        ])->id]);

        $cart = $this->getJson('/api/v1/pos/cart/'.$cartId, $this->headers())->assertOk()->json('data');

        $this->assertSame('551000.0000', $cart['subtotal']);
        $this->assertSame('50000.0000', $cart['tax_total']);
        // Only the tax inside the quoted prices is reported as "included".
        $this->assertSame('50000.0000', $cart['tax_included_total']);
        // The inclusive line is already whole, so it is not grossed up again.
        $this->assertSame('551000.0000', $cart['grand_total']);
    }

    public function test_a_cart_discount_is_applied_once_however_often_the_cart_recomputes(): void
    {
        $cartId = $this->cartId();
        // Two lines x 25000 + 11%: subtotal 50000, tax 5500.
        $this->addProduct($cartId, ['product_id' => $this->coffee->id, 'quantity' => '2']);

        $cart = $this->putJson("/api/v1/pos/cart/{$cartId}", [
            'discount_input' => '10',
            'discount_type' => 'percent',
        ], $this->headers())->assertOk()->json('data');

        // The spec's header formula is subtotal - discount + tax, so the
        // discount lands after the lines have been taxed: a cart-wide 10%
        // takes 5000 off the bill, it does not restate each line's PPN.
        $this->assertSame('50000.0000', $cart['subtotal']);
        $this->assertSame('5000.0000', $cart['discount_total']);
        $this->assertSame('5500.0000', $cart['tax_total']);
        $this->assertSame('50500.0000', $cart['grand_total']);

        // A second write must not apply the 10% a second time.
        $again = $this->putJson("/api/v1/pos/cart/{$cartId}", ['notes' => 'loyal customer'], $this->headers())
            ->assertOk()->json('data');

        // The entry is still the raw "10 percent"; only discount_total is the
        // resolved figure, which is why storing one inside the other was not an
        // option.
        $this->assertSame('10.0000', $again['discount_input']);
        $this->assertSame('percent', $again['discount_type']);
        $this->assertSame('5000.0000', $again['discount_total']);
        $this->assertSame('50500.0000', $again['grand_total']);
    }

    public function test_totals_are_the_servers_even_when_the_client_sends_its_own(): void
    {
        $cartId = $this->cartId();
        $this->addProduct($cartId, ['product_id' => $this->coffee->id]);

        $response = $this->putJson("/api/v1/pos/cart/{$cartId}", [
            'notes' => 'walk in',
            'subtotal' => '1.0000',
            'tax_total' => '1.0000',
            'discount_total' => '1.0000',
            'grand_total' => '999999.0000',
            'items' => [['product_id' => 999, 'quantity' => '5']],
            'status' => 'held',
        ], $this->headers())->assertOk();

        $cart = $response->json('data');
        $this->assertSame('25000.0000', $cart['subtotal']);
        $this->assertSame('27750.0000', $cart['grand_total']);
        $this->assertSame('active', $cart['status'], 'a client cannot park its own cart');
        $this->assertCount(1, $cart['items']);
        $this->assertSame($this->coffee->id, $cart['items'][0]['product_id']);
    }

    public function test_other_charges_and_rounding_are_shown_as_signed_corrections(): void
    {
        // A price with a half-rupia: the till displays whole rupiah, so the
        // half must be accounted for rather than dropped.
        $odd = $this->product(['name' => 'Filter Paper', 'sku' => 'FLT-1', 'selling_price' => '12500.5000', 'tax_id' => null]);
        $cartId = $this->cartId();
        $this->addProduct($cartId, ['product_id' => $odd->id, 'quantity' => '3']);

        $cart = $this->putJson("/api/v1/pos/cart/{$cartId}", ['other_charges' => '2000'], $this->headers())
            ->assertOk()->json('data');

        // 3 x 12500.50 = 37501.5000 + 2000 = 39501.5000 -> 39502.
        $this->assertSame('37501.5000', $cart['subtotal']);
        $this->assertSame('2000.0000', $cart['other_charges']);
        $this->assertSame('0.5000', $cart['rounding']);
        $this->assertSame('39502.0000', $cart['grand_total']);
    }

    public function test_money_crosses_the_wire_as_strings(): void
    {
        $cart = $this->addProduct($this->cartId(), ['product_id' => $this->coffee->id]);

        foreach (['subtotal', 'tax_total', 'grand_total', 'rounding', 'discount_total'] as $field) {
            $this->assertIsString($cart[$field], "{$field} must be a decimal string");
        }
        $this->assertIsString($cart['items'][0]['quantity']);
        $this->assertIsString($cart['items'][0]['unit_price']);
    }

    //
    // Drafts must stay drafts
    //

    public function test_a_cart_never_moves_stock(): void
    {
        StockBalance::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->coffee->id,
            'unit_id' => $this->unit->id,
            'on_hand' => '100.000000',
            'reserved' => '0.000000',
        ]);
        $beforeBalances = StockBalance::orderBy('id')->get('on_hand')->pluck('on_hand')->all();

        $cartId = $this->cartId();
        $this->addProduct($cartId, ['product_id' => $this->coffee->id, 'quantity' => '40']);
        $this->addProduct($cartId, ['barcode' => $this->coffee->barcode]);
        $this->putJson("/api/v1/pos/cart/{$cartId}", ['discount_input' => '5'], $this->headers())->assertOk();
        $this->postJson("/api/v1/pos/cart/{$cartId}/hold", [], $this->headers())->assertOk();

        $this->assertSame($beforeBalances, StockBalance::orderBy('id')->get('on_hand')->pluck('on_hand')->all());
        $this->assertSame(0, StockMovement::count(), 'a draft must not write the ledger');
        // 61 units rang up, and the shelf still reports all 100.
        $this->assertSame('100.000000', $this->getJson('/api/v1/pos/products/search?barcode='.$this->coffee->barcode, $this->headers())
            ->assertOk()->json('data.product.stock.on_hand'));
    }

    public function test_a_draft_writes_nothing_outside_the_cart_tables(): void
    {
        // Checkout owns invoices, payments and journals; until Subphase 3.2
        // ships there are no such tables at all, which is the point — a cart
        // cannot write a document that does not exist yet.
        $cartId = $this->cartId();
        $this->addProduct($cartId, ['product_id' => $this->coffee->id]);
        $this->putJson("/api/v1/pos/cart/{$cartId}", ['other_charges' => '500'], $this->headers())->assertOk();
        $this->postJson("/api/v1/pos/cart/{$cartId}/hold", [], $this->headers())->assertOk();

        $this->assertSame(1, PosCart::count());
        $this->assertSame(1, PosCartItem::count());
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('stock_balances', 0);
        foreach (['invoices', 'invoice_items', 'payments', 'journals', 'journal_entries'] as $absent) {
            $this->assertFalse(Schema::hasTable($absent), "{$absent} should belong to a later subphase");
        }
    }

    //
    // Hold and recall
    //

    public function test_holding_a_cart_hands_back_a_recall_code(): void
    {
        $cartId = $this->cartId();
        $this->addProduct($cartId, ['product_id' => $this->coffee->id]);

        $response = $this->postJson("/api/v1/pos/cart/{$cartId}/hold", [], $this->headers());
        $response->assertOk();

        $this->assertSame('held', $response->json('data.status'));
        $this->assertMatchesRegularExpression('/^PARK-\d{8}-\d{4}$/', $response->json('data.number'));
        $this->assertNotNull($response->json('data.held_at'));
        $this->assertStringContainsString($response->json('data.number'), $response->json('message'));

        // The till is now free for the next customer.
        $this->assertNotSame($cartId, $this->openCart()['id']);
    }

    public function test_an_empty_cart_is_not_worth_a_park(): void
    {
        $this->postJson("/api/v1/pos/cart/{$this->cartId()}/hold", [], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('errors.cart.0', 'There is nothing on hold yet — add items first.');
    }

    public function test_a_held_cart_cannot_be_edited_until_it_is_recalled(): void
    {
        $cartId = $this->cartId();
        $this->addProduct($cartId, ['product_id' => $this->coffee->id]);
        $held = $this->postJson("/api/v1/pos/cart/{$cartId}/hold", [], $this->headers())->assertOk()->json('data');

        $this->postJson("/api/v1/pos/cart/{$cartId}/items", ['product_id' => $this->coffee->id], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('errors.cart.0', 'This cart is on hold. Recall it before making changes.');

        $this->putJson("/api/v1/pos/cart/{$cartId}", ['notes' => 'sneaky'], $this->headers())
            ->assertStatus(422);

        $this->assertSame($held['grand_total'], $this->getJson('/api/v1/pos/cart/'.$cartId, $this->headers())
            ->assertOk()->json('data.grand_total'), 'a parked draft keeps its money');
    }

    public function test_a_parked_cart_comes_back_to_the_till_by_its_code(): void
    {
        $cartId = $this->cartId();
        $mug = $this->product(['name' => 'Ceramic Mug', 'sku' => 'MUG-1', 'selling_price' => '40000.0000']);
        $this->addProduct($cartId, ['product_id' => $mug->id, 'quantity' => '2']);
        $held = $this->postJson("/api/v1/pos/cart/{$cartId}/hold", [], $this->headers())->assertOk()->json('data');

        $recalled = $this->postJson('/api/v1/pos/cart/recall', ['number' => $held['number']], $this->headers())
            ->assertOk()->json('data');

        $this->assertSame($cartId, $recalled['id']);
        $this->assertSame('active', $recalled['status']);
        $this->assertNull($recalled['number'], 'the code is spent once the cart is back');
        $this->assertSame('80000.0000', $recalled['subtotal']);
        $this->assertSame($cartId, $this->openCart()['id'], 'the recalled cart is the working one again');
    }

    public function test_a_recall_will_not_silently_replace_unfinished_work(): void
    {
        $first = $this->cartId();
        $this->addProduct($first, ['product_id' => $this->coffee->id]);
        $held = $this->postJson("/api/v1/pos/cart/{$first}/hold", [], $this->headers())->assertOk()->json('data');

        // The cashier starts serving the next customer, then remembers.
        $second = $this->cartId();
        $this->addProduct($second, ['product_id' => $this->coffee->id, 'quantity' => '2']);

        $this->postJson('/api/v1/pos/cart/recall', ['number' => $held['number']], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('errors.cart.0', 'Finish or hold the current cart before recalling another one.');

        $this->assertSame('held', $this->getJson('/api/v1/pos/cart/'.$first, $this->headers())->assertOk()->json('data.status'));
        $this->assertCount(1, $this->getJson('/api/v1/pos/cart/'.$second, $this->headers())->assertOk()->json('data.items'));

        // Clearing the till lets the recall through, and the empty cart is gone.
        $this->deleteJson("/api/v1/pos/cart/{$second}/items", [], $this->headers())->assertOk();
        $this->postJson('/api/v1/pos/cart/recall', ['number' => $held['number']], $this->headers())->assertOk();
        // The emptied cart is dropped outright rather than parked as a second
        // active row, which would break the one-working-cart-per-till rule.
        $this->assertDatabaseMissing('pos_carts', ['id' => $second]);
    }

    public function test_an_unknown_code_is_answered_with_a_clear_no(): void
    {
        $this->postJson('/api/v1/pos/cart/recall', ['number' => 'PARK-20260101-0001'], $this->headers())
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_a_recall_needs_permission(): void
    {
        $cartId = $this->cartId();
        $this->addProduct($cartId, ['product_id' => $this->coffee->id]);
        $held = $this->postJson("/api/v1/pos/cart/{$cartId}/hold", [], $this->headers())->assertOk()->json('data');

        $noHold = $this->colleague(['pos.view', 'pos.transact']);
        $this->postJson('/api/v1/pos/cart/recall', ['number' => $held['number']], $this->authHeaders($noHold, $this->context()))
            ->assertForbidden();
    }

    public function test_the_parked_queue_is_a_list_a_cashier_can_pick_from(): void
    {
        $mug = $this->product(['name' => 'Ceramic Mug', 'sku' => 'MUG-1', 'selling_price' => '40000.0000']);
        $codes = [];
        foreach ([[$this->coffee, '1'], [$mug, '3']] as [$product, $qty]) {
            $cartId = $this->cartId();
            $this->addProduct($cartId, ['product_id' => $product->id, 'quantity' => $qty]);
            $codes[] = $this->postJson("/api/v1/pos/cart/{$cartId}/hold", [], $this->headers())->assertOk()->json('data.number');
        }

        $queue = $this->getJson('/api/v1/pos/cart/held', $this->headers())->assertOk()->json('data');

        $this->assertSame($codes, array_column($queue, 'number'));
        $this->assertSame(1, $queue[0]['item_count']);
        $this->assertSame('1.000000', $queue[0]['total_quantity']);
        $this->assertSame('27750.0000', $queue[0]['grand_total']);
        $this->assertSame('3.000000', $queue[1]['total_quantity']);
        $this->assertSame('133200.0000', $queue[1]['grand_total']);
        $this->assertArrayHasKey('cashier', $queue[0]);

        // Once recalled, a cart leaves the queue.
        $this->postJson('/api/v1/pos/cart/recall', ['number' => $codes[0]], $this->headers())->assertOk();
        $this->assertSame([$codes[1]], array_column(
            $this->getJson('/api/v1/pos/cart/held', $this->headers())->assertOk()->json('data'), 'number'
        ));
    }

    public function test_a_parked_draft_can_be_discarded(): void
    {
        $cartId = $this->cartId();
        $this->addProduct($cartId, ['product_id' => $this->coffee->id]);
        $this->postJson("/api/v1/pos/cart/{$cartId}/hold", [], $this->headers())->assertOk();

        $this->deleteJson("/api/v1/pos/cart/{$cartId}", [], $this->headers())->assertOk();
        $this->assertSoftDeleted('pos_carts', ['id' => $cartId]);
        $this->assertSame([], $this->getJson('/api/v1/pos/cart/held', $this->headers())->assertOk()->json('data'));
    }

    public function test_a_working_cart_cannot_be_deleted_as_a_shortcut(): void
    {
        $cartId = $this->cartId();
        $this->addProduct($cartId, ['product_id' => $this->coffee->id]);

        $this->deleteJson("/api/v1/pos/cart/{$cartId}", [], $this->headers())->assertStatus(422);
        $this->assertDatabaseHas('pos_carts', ['id' => $cartId, 'deleted_at' => null]);
    }

    //
    // Access
    //

    public function test_a_colleague_cannot_work_on_someones_open_cart(): void
    {
        $cartId = $this->cartId();
        $this->addProduct($cartId, ['product_id' => $this->coffee->id]);

        $other = $this->colleague(['pos.view', 'pos.transact', 'pos.hold']);
        $headers = $this->authHeaders($other, $this->context());

        // Same company, same register, sufficient permission — still not their cart.
        $this->postJson("/api/v1/pos/cart/{$cartId}/items", ['product_id' => $this->coffee->id], $headers)
            ->assertForbidden();
        $this->putJson("/api/v1/pos/cart/{$cartId}", ['notes' => 'mine'], $headers)->assertForbidden();
        $this->postJson("/api/v1/pos/cart/{$cartId}/hold", [], $headers)->assertForbidden();
    }

    public function test_a_park_can_be_picked_up_by_another_cashier_at_the_same_shop(): void
    {
        $cartId = $this->cartId();
        $this->addProduct($cartId, ['product_id' => $this->coffee->id]);
        $held = $this->postJson("/api/v1/pos/cart/{$cartId}/hold", [], $this->headers())->assertOk()->json('data');

        $other = $this->colleague(['pos.view', 'pos.transact', 'pos.hold']);
        $recalled = $this->postJson('/api/v1/pos/cart/recall', ['number' => $held['number']], $this->authHeaders($other, $this->context()))
            ->assertOk()->json('data');

        $this->assertSame($other->id, $recalled['user_id'], 'the draft follows whoever picks it up');
    }

    public function test_tilling_needs_a_permission_and_viewing_does_not_grant_it(): void
    {
        $watcher = $this->colleague(['pos.view']);
        $headers = $this->authHeaders($watcher, $this->context());

        $cart = $this->getJson('/api/v1/pos/cart', $headers);
        // Opening the till is a read of one's own draft, so pos.view is enough.
        $cart->assertOk();
        $cartId = $cart->json('data.id');

        $this->postJson("/api/v1/pos/cart/{$cartId}/items", ['product_id' => $this->coffee->id], $headers)
            ->assertForbidden();
        $this->postJson("/api/v1/pos/cart/{$cartId}/hold", [], $headers)->assertForbidden();

        $noTill = $this->colleague(['products.view']);
        $this->getJson('/api/v1/pos/cart', $this->authHeaders($noTill, $this->context()))->assertForbidden();
        $this->getJson('/api/v1/pos/cart/held', $this->authHeaders($noTill, $this->context()))->assertForbidden();
    }

    public function test_one_shop_cannot_read_or_recall_anothers_draft(): void
    {
        $cartId = $this->cartId();
        $this->addProduct($cartId, ['product_id' => $this->coffee->id]);
        $held = $this->postJson("/api/v1/pos/cart/{$cartId}/hold", [], $this->headers())->assertOk()->json('data');

        $theirs = Company::factory()->create();
        $outsider = $this->colleague(['pos.view', 'pos.transact', 'pos.hold'], $theirs);
        $headers = $this->authHeaders($outsider, ['company_id' => $theirs->id]);

        $this->getJson('/api/v1/pos/cart/held', $headers)->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/pos/cart/recall', ['number' => $held['number']], $headers)->assertNotFound();
        $this->getJson('/api/v1/pos/cart/'.$cartId, $headers)->assertForbidden();
        $this->deleteJson("/api/v1/pos/cart/{$cartId}", [], $headers)->assertForbidden();
    }

    public function test_the_till_answers_with_the_standard_envelope(): void
    {
        $this->getJson('/api/v1/pos/cart', $this->headers())
            ->assertOk()
            ->assertJsonStructure(['success', 'message', 'data', 'meta']);

        $this->getJson('/api/v1/pos/products/search', $this->headers())
            ->assertOk()
            ->assertJsonStructure(['success', 'message', 'data', 'meta']);

        $cartId = $this->cartId();
        $this->postJson("/api/v1/pos/cart/{$cartId}/items", ['product_id' => $this->coffee->id], $this->headers())
            ->assertCreated()
            ->assertJsonStructure(['success', 'message', 'data' => ['item', 'cart'], 'meta']);
    }

    /**
     * A second cashier of the same (or a different) company.
     */
    protected function colleague(array $permissions, ?Company $in = null): User
    {
        $company = $in ?? $this->company;
        $user = User::factory()->create();
        $user->companies()->attach($company->id);
        $user->branches()->attach($this->branch->id);
        $user->warehouses()->attach($this->warehouse->id);
        $user->registers()->attach($this->register->id);

        $role = Role::create([
            'company_id' => $company->id,
            'name' => 'cashier_'.uniqid(),
            'display_name' => 'Cashier',
        ]);
        $role->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id')->all());
        $user->roles()->attach($role->id);
        $user->clearPermissionCache($company->id);

        return $user;
    }
}
