<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseRequestStatus;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Tax;
use App\Models\Unit;
use App\Models\User;
use Tests\TestCase;

class PurchaseOrderTest extends TestCase
{
    protected User $user;

    protected Unit $unit;

    protected Supplier $supplier;

    protected Tax $tax;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->authenticatedUser([
            'purchases.view', 'purchases.create', 'purchases.update',
            'purchases.approve', 'purchases.receive', 'purchases.cancel',
        ]);

        $this->unit = Unit::factory()->for($this->company)->create();
        $this->supplier = Supplier::factory()->for($this->company)->create();
        $this->tax = Tax::factory()->for($this->company)->create([
            'rate' => '11.0000',
            'type' => 'exclusive',
        ]);
        $this->product = $this->makeProduct();
    }

    protected function makeProduct(): Product
    {
        return Product::factory()->for($this->company)->create([
            'default_unit_id' => $this->unit->id,
            'tax_id' => $this->tax->id,
            'is_purchasable' => true,
            'cost_price' => '1000.0000',
        ]);
    }

    protected function headers(): array
    {
        return $this->authHeaders($this->user);
    }

    /**
     * A second user inside the same business tree, holding only the listed
     * permissions, so the security tests exercise real permission checks.
     */
    protected function restrictedUser(array $permissions): User
    {
        $user = User::factory()->create();
        $user->companies()->attach($this->company->id);
        $user->branches()->attach($this->branch->id);
        $user->warehouses()->attach($this->warehouse->id);

        $role = Role::create([
            'company_id' => $this->company->id,
            'name' => 'restricted_'.uniqid(),
            'display_name' => 'Restricted',
        ]);

        $role->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id')->all());
        $user->roles()->attach($role->id);
        $user->clearPermissionCache($this->company->id);

        return $user;
    }

    /**
     * Money and quantities are spoken as exact decimal strings: a JSON number
     * is a float, and a float is exactly what the decimal engine exists to
     * keep out of the ledger.
     */
    protected function createOrder(array $items, array $overrides = []): PurchaseOrder
    {
        $response = $this->postJson('/api/v1/purchase-orders', array_merge([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->supplier->id,
            'order_date' => now()->toDateString(),
            'expected_date' => now()->addDays(7)->toDateString(),
            'items' => $items,
        ], $overrides), $this->headers());

        $response->assertCreated();

        return PurchaseOrder::find($response->json('data.id'));
    }

    protected function line(int $productId, string $quantity, string $unitPrice, array $extra = []): array
    {
        return array_merge([
            'product_id' => $productId,
            'unit_id' => $this->unit->id,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
        ], $extra);
    }

    /**
     * Build an order straight against the model, skipping the HTTP layer.
     *
     * The auth guard is resolved once and cached for the life of a single test
     * method, so a request issued as one user poisons every later request in
     * that method. Where a test needs a second user to hit an endpoint, the
     * fixture is created here rather than through an API call.
     */
    protected function makeOrder(PurchaseOrderStatus $status = PurchaseOrderStatus::Draft): PurchaseOrder
    {
        $order = PurchaseOrder::factory()
            ->for($this->company)
            ->for($this->warehouse)
            ->for($this->supplier)
            ->create(['status' => $status]);

        $order->items()->create([
            'product_id' => $this->product->id,
            'unit_id' => $this->unit->id,
            'quantity' => '10.000000',
            'unit_price' => '1000.0000',
            'discount' => '0.0000',
            'discount_type' => 'amount',
            'tax_rate' => '0.0000',
            'net_price' => '10000.0000',
            'tax_amount' => '0.0000',
            'subtotal' => '10000.0000',
        ]);

        return $order->fresh();
    }

    //
    // Money: the decimal engine
    //

    public function test_totals_are_recomputed_server_side_and_ignore_client_values(): void
    {
        // The spec's example document: one line with a percentage discount and
        // taxed at 11%, one with a flat-amount discount and no tax.
        $order = $this->createOrder([
            $this->line($this->product->id, '10.000000', '1000.0000', [
                'discount' => '10.0000',
                'discount_type' => 'percent',
                'tax_id' => $this->tax->id,
            ]),
            $this->line($this->product->id, '5.000000', '200.0000', [
                'discount' => '100.0000',
                'discount_type' => 'amount',
            ]),
        ], [
            'discount_total' => '500.0000',
            'shipping_cost' => '250.0000',
            'other_charges' => '50.0000',
            // Deliberately wrong; every one must be overwritten.
            'subtotal' => '1.0000',
            'item_discount_total' => '1.0000',
            'tax_total' => '1.0000',
            'grand_total' => '999999.0000',
        ]);

        $order->refresh();

        $this->assertSame('11000.0000', (string) $order->subtotal);
        $this->assertSame('1100.0000', (string) $order->item_discount_total);
        $this->assertSame('500.0000', (string) $order->discount_total);
        $this->assertSame('990.0000', (string) $order->tax_total);
        $this->assertSame('250.0000', (string) $order->shipping_cost);
        $this->assertSame('50.0000', (string) $order->other_charges);
        $this->assertSame('10690.0000', (string) $order->grand_total);
    }

    public function test_calculation_correctness_to_the_last_decimal_place(): void
    {
        // Line 1: 10 x 1000 = 10000 gross, 10% off = 1000 discount,
        // net 9000, exclusive 11% tax = 990, line total 9990.
        // Line 2: 5 x 200 = 1000 gross, flat 100 off, net 900, no tax.
        // Header: 11000 gross - 1100 item discounts - 500 header discount
        //         + 990 tax + 250 shipping + 50 other = 10690.
        $order = $this->createOrder([
            $this->line($this->product->id, '10.000000', '1000.0000', [
                'discount' => '10.0000',
                'discount_type' => 'percent',
                'tax_id' => $this->tax->id,
            ]),
            $this->line($this->product->id, '5.000000', '200.0000', [
                'discount' => '100.0000',
                'discount_type' => 'amount',
                'tax_rate' => '0.0000',
            ]),
        ], [
            'discount_total' => '500.0000',
            'shipping_cost' => '250.0000',
            'other_charges' => '50.0000',
        ]);

        $lines = $order->refresh()->items;

        // Line 1: percentage discount, taxed.
        $this->assertSame('9000.0000', (string) $lines[0]->net_price);
        $this->assertSame('990.0000', (string) $lines[0]->tax_amount);
        $this->assertSame('9990.0000', (string) $lines[0]->subtotal);

        // Line 2: flat-amount discount, untaxed.
        $this->assertSame('900.0000', (string) $lines[1]->net_price);
        $this->assertSame('0.0000', (string) $lines[1]->tax_amount);
        $this->assertSame('900.0000', (string) $lines[1]->subtotal);

        $this->assertSame('10690.0000', (string) $order->grand_total);
    }

    public function test_item_discount_as_percent_and_as_amount_are_both_correct(): void
    {
        // 25% off a 4 x 1000 line: gross 4000, discount 1000, net 3000.
        $order = $this->createOrder([
            $this->line($this->product->id, '4.000000', '1000.0000', [
                'discount' => '25.0000',
                'discount_type' => 'percent',
            ]),
        ]);

        $line = $order->refresh()->items->first();
        // 4000 gross - 1000 discount = 3000 net.
        $this->assertSame('1000.0000', (string) $order->item_discount_total);
        $this->assertSame('3000.0000', (string) $line->net_price);

        // A flat 250 off the same line: gross 4000, net 3750.
        $order = $this->createOrder([
            $this->line($this->product->id, '4.000000', '1000.0000', [
                'discount' => '250.0000',
                'discount_type' => 'amount',
            ]),
        ]);

        $line = $order->refresh()->items->first();
        $this->assertSame('250.0000', (string) $order->item_discount_total);
        $this->assertSame('3750.0000', (string) $line->net_price);
    }

    public function test_tax_rate_is_snapshotted_from_the_tax_id_and_not_the_live_rate(): void
    {
        $order = $this->createOrder([
            $this->line($this->product->id, '10.000000', '1000.0000', [
                'tax_id' => $this->tax->id,
            ]),
        ]);

        $line = $order->refresh()->items->first();
        $this->assertSame('11.0000', (string) $line->tax_rate);
        $this->assertSame('1100.0000', (string) $line->tax_amount);

        // A later rate change must not rewrite this order's taxed history.
        $this->tax->update(['rate' => '50.0000']);
        $order->refresh();

        $this->assertSame('11.0000', (string) $order->items->first()->tax_rate);
        $this->assertSame('1100.0000', (string) $order->items->first()->tax_amount);
        $this->assertSame('11100.0000', (string) $order->grand_total);
    }

    public function test_updating_lines_recomputes_the_totals(): void
    {
        $order = $this->createOrder([
            $this->line($this->product->id, '10.000000', '1000.0000'),
        ]);

        $this->assertSame('10000.0000', (string) $order->grand_total);

        $response = $this->putJson("/api/v1/purchase-orders/{$order->id}", [
            'discount_total' => '1000.0000',
            'items' => [
                $this->line($this->product->id, '20.000000', '1000.0000'),
            ],
        ], $this->headers());

        $response->assertOk();
        $order->refresh();

        $this->assertSame('20000.0000', (string) $order->subtotal);
        $this->assertSame('1000.0000', (string) $order->discount_total);
        $this->assertSame('19000.0000', (string) $order->grand_total);
        $this->assertCount(1, $order->items);
        $this->assertSame('20.000000', (string) $order->items->first()->quantity);
    }

    public function test_an_empty_items_array_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/purchase-orders', [
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->supplier->id,
            'order_date' => now()->toDateString(),
            'items' => [],
        ], $this->headers());

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('items');
        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_non_purchasable_product_is_rejected(): void
    {
        $notPurchasable = Product::factory()->for($this->company)->create([
            'default_unit_id' => $this->unit->id,
            'is_purchasable' => false,
        ]);

        $response = $this->postJson('/api/v1/purchase-orders', [
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->supplier->id,
            'order_date' => now()->toDateString(),
            'items' => [$this->line($notPurchasable->id, '1.000000', '10.0000')],
        ], $this->headers());

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('items.0.product_id');
    }

    public function test_supplier_from_another_company_is_rejected(): void
    {
        $otherCompany = Company::factory()->create();
        $foreignSupplier = Supplier::factory()->for($otherCompany)->create();

        $response = $this->postJson('/api/v1/purchase-orders', [
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $foreignSupplier->id,
            'order_date' => now()->toDateString(),
            'items' => [$this->line($this->product->id, '1.000000', '10.0000')],
        ], $this->headers());

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('supplier_id');
    }

    public function test_quantity_must_be_positive(): void
    {
        $response = $this->postJson('/api/v1/purchase-orders', [
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->supplier->id,
            'order_date' => now()->toDateString(),
            'items' => [$this->line($this->product->id, '0.000000', '10.0000')],
        ], $this->headers());

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('items.0.quantity');
    }

    //
    // State machine
    //

    public function test_forward_transitions_walk_to_sent(): void
    {
        $order = $this->createOrder([
            $this->line($this->product->id, '10.000000', '1000.0000'),
        ]);

        $this->assertSame(PurchaseOrderStatus::Draft->value, $order->status->value);

        $this->postJson("/api/v1/purchase-orders/{$order->id}/submit", [], $this->headers())->assertOk();
        $this->assertSame('submitted', $order->fresh()->status->value);

        $this->postJson("/api/v1/purchase-orders/{$order->id}/approve", [], $this->headers())->assertOk();
        $order->refresh();
        $this->assertSame('approved', $order->status->value);
        $this->assertSame($this->user->id, $order->approved_by);
        $this->assertNotNull($order->approved_at);

        $this->postJson("/api/v1/purchase-orders/{$order->id}/send", [], $this->headers())->assertOk();
        $this->assertSame('sent', $order->fresh()->status->value);
        $this->assertNotNull($order->fresh()->sent_at);
    }

    public function test_out_of_order_transitions_are_rejected(): void
    {
        $order = $this->createOrder([
            $this->line($this->product->id, '10.000000', '1000.0000'),
        ]);

        // A draft cannot be approved or sent directly.
        $approve = $this->postJson("/api/v1/purchase-orders/{$order->id}/approve", [], $this->headers());
        $approve->assertStatus(422);
        $approve->assertJsonValidationErrors('status');
        $this->assertStringContainsString('draft', $approve->json('errors.status.0'));

        $send = $this->postJson("/api/v1/purchase-orders/{$order->id}/send", [], $this->headers());
        $send->assertStatus(422);

        $this->assertSame('draft', $order->fresh()->status->value);

        // A submitted order cannot be cancelled (only a draft can).
        $this->postJson("/api/v1/purchase-orders/{$order->id}/submit", [], $this->headers())->assertOk();

        $cancel = $this->postJson("/api/v1/purchase-orders/{$order->id}/cancel", [], $this->headers());
        $cancel->assertStatus(422);
        $cancel->assertJsonValidationErrors('status');
        $this->assertSame('submitted', $order->fresh()->status->value);
    }

    public function test_draft_can_be_cancelled_and_a_cancelled_order_cannot_receive(): void
    {
        $order = $this->createOrder([
            $this->line($this->product->id, '10.000000', '1000.0000'),
        ]);

        $this->assertTrue($order->canReceive());

        $response = $this->postJson("/api/v1/purchase-orders/{$order->id}/cancel", [], $this->headers());
        $response->assertOk();
        $this->assertSame('cancelled', $order->fresh()->status->value);

        // Spec §49: a cancelled order is shut to goods receipts.
        $this->assertFalse($order->fresh()->canReceive());

        // And progress recomputation must not revive it.
        $order->refresh();
        $order->items->first()->forceFill(['quantity_received' => '10.000000'])->save();
        $order->markReceivedProgress();
        $this->assertSame('cancelled', $order->fresh()->status->value);
    }

    public function test_mark_received_progress_sets_partial_and_full_status(): void
    {
        $order = $this->createOrder([
            $this->line($this->product->id, '10.000000', '1000.0000'),
            $this->line($this->product->id, '5.000000', '200.0000'),
        ]);

        // Half of one line landed.
        $order->items->first()->forceFill(['quantity_received' => '5.000000'])->save();
        $order->markReceivedProgress();

        $this->assertSame('partially_received', $order->fresh()->status->value);

        // Everything landed.
        foreach ($order->refresh()->items as $item) {
            $item->forceFill(['quantity_received' => (string) $item->quantity])->save();
        }
        $order->markReceivedProgress();

        $this->assertSame('received', $order->fresh()->status->value);
    }

    public function test_received_order_can_be_closed(): void
    {
        $order = $this->createOrder([
            $this->line($this->product->id, '10.000000', '1000.0000'),
        ]);

        $order->forceFill(['status' => PurchaseOrderStatus::Received])->save();

        $response = $this->postJson("/api/v1/purchase-orders/{$order->id}/close", [], $this->headers());
        $response->assertOk();

        $order->refresh();
        $this->assertSame('closed', $order->status->value);
        $this->assertNotNull($order->closed_at);
        $this->assertFalse($order->canReceive());
    }

    public function test_a_submitted_order_cannot_be_edited(): void
    {
        $order = $this->createOrder([
            $this->line($this->product->id, '10.000000', '1000.0000'),
        ]);

        $this->postJson("/api/v1/purchase-orders/{$order->id}/submit", [], $this->headers())->assertOk();

        $response = $this->putJson("/api/v1/purchase-orders/{$order->id}", [
            'notes' => 'tampered',
        ], $this->headers());

        $response->assertStatus(422);
        $this->assertNull($order->fresh()->notes);
    }

    //
    // Purchase request conversion
    //

    public function test_convert_creates_a_draft_order_and_marks_the_request_converted(): void
    {
        $other = $this->makeProduct();

        $response = $this->postJson('/api/v1/purchase-requests', [
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->supplier->id,
            'request_date' => now()->toDateString(),
            'required_date' => now()->addDays(7)->toDateString(),
            'items' => [
                $this->line($this->product->id, '10.000000', '0.0000'),
                $this->line($other->id, '4.000000', '0.0000'),
            ],
        ], $this->headers());

        $response->assertCreated();
        $request = PurchaseRequest::find($response->json('data.id'));
        $this->assertSame($this->user->id, $request->requested_by);

        $this->postJson("/api/v1/purchase-requests/{$request->id}/submit", [], $this->headers())->assertOk();
        $this->postJson("/api/v1/purchase-requests/{$request->id}/approve", [], $this->headers())->assertOk();
        $request->refresh();
        $this->assertSame('approved', $request->status->value);
        $this->assertSame($this->user->id, $request->approved_by);

        $convert = $this->postJson("/api/v1/purchase-requests/{$request->id}/convert", [], $this->headers());
        $convert->assertOk();

        $request->refresh();
        $this->assertSame('converted', $request->status->value);
        $this->assertNotNull($request->converted_at);

        // One draft order carrying the remaining quantity of every line.
        $order = PurchaseOrder::query()
            ->where('purchase_request_id', $request->id)
            ->firstOrFail();

        $this->assertSame('draft', $order->status->value);
        $this->assertSame($this->supplier->id, $order->supplier_id);
        $this->assertCount(2, $order->items);

        $lines = $order->items->keyBy('product_id');
        $this->assertSame('10.000000', (string) $lines[$this->product->id]->quantity);
        $this->assertSame('4.000000', (string) $lines[$other->id]->quantity);

        // Every line of the request is consumed by the conversion.
        foreach ($request->fresh()->items as $item) {
            $this->assertSame((string) $item->quantity, (string) $item->quantity_converted);
        }

        // The spawned order's totals are derived, not copied. Both lines carry
        // the products' 11% tax on the cost price of 1000:
        // 10 x 1000 + 11% = 11100, 4 x 1000 + 11% = 4440.
        $this->assertSame('14000.0000', (string) $order->subtotal);
        $this->assertSame('1540.0000', (string) $order->tax_total);
        $this->assertSame('15540.0000', (string) $order->grand_total);
    }

    public function test_double_convert_is_rejected(): void
    {
        $request = PurchaseRequest::factory()
            ->for($this->company)
            ->for($this->warehouse)
            ->for($this->supplier)
            ->create(['status' => PurchaseRequestStatus::Approved]);

        $request->items()->create([
            'product_id' => $this->product->id,
            'unit_id' => $this->unit->id,
            'quantity' => '10.000000',
        ]);

        $first = $this->postJson("/api/v1/purchase-requests/{$request->id}/convert", [], $this->headers());
        $first->assertOk();
        $this->assertSame('converted', $request->fresh()->status->value);

        $second = $this->postJson("/api/v1/purchase-requests/{$request->id}/convert", [], $this->headers());
        $second->assertStatus(422);
        $second->assertJsonValidationErrors('status');

        $this->assertSame(1, PurchaseOrder::query()->where('purchase_request_id', $request->id)->count());
    }

    public function test_a_rejected_request_cannot_be_converted(): void
    {
        $request = PurchaseRequest::factory()
            ->for($this->company)
            ->for($this->warehouse)
            ->for($this->supplier)
            ->create(['status' => PurchaseRequestStatus::Rejected]);

        $request->items()->create([
            'product_id' => $this->product->id,
            'unit_id' => $this->unit->id,
            'quantity' => '10.000000',
        ]);

        $response = $this->postJson("/api/v1/purchase-requests/{$request->id}/convert", [], $this->headers());

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('status');
        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_convert_without_a_supplier_is_rejected(): void
    {
        $request = PurchaseRequest::factory()
            ->for($this->company)
            ->for($this->warehouse)
            ->create([
                'supplier_id' => null,
                'status' => PurchaseRequestStatus::Approved,
            ]);

        $request->items()->create([
            'product_id' => $this->product->id,
            'unit_id' => $this->unit->id,
            'quantity' => '10.000000',
        ]);

        $response = $this->postJson("/api/v1/purchase-requests/{$request->id}/convert", [], $this->headers());

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('supplier_id');
    }

    public function test_purchase_request_out_of_order_transition_is_rejected(): void
    {
        $request = PurchaseRequest::factory()
            ->for($this->company)
            ->for($this->warehouse)
            ->create(['status' => PurchaseRequestStatus::Draft]);

        // A draft cannot be approved directly.
        $response = $this->postJson("/api/v1/purchase-requests/{$request->id}/approve", [], $this->headers());
        $response->assertStatus(422);
        $response->assertJsonValidationErrors('status');
        $this->assertSame('draft', $request->fresh()->status->value);
    }

    //
    // Security
    //

    public function test_user_without_create_permission_is_forbidden(): void
    {
        $viewer = $this->restrictedUser(['purchases.view']);

        $response = $this->postJson('/api/v1/purchase-orders', [
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->supplier->id,
            'order_date' => now()->toDateString(),
            'items' => [$this->line($this->product->id, '1.000000', '10.0000')],
        ], $this->authHeaders($viewer));

        $response->assertForbidden();
        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_user_without_approve_permission_cannot_approve(): void
    {
        $buyer = $this->restrictedUser(['purchases.view', 'purchases.create', 'purchases.update']);

        $order = $this->makeOrder(PurchaseOrderStatus::Submitted);

        $response = $this->postJson("/api/v1/purchase-orders/{$order->id}/approve", [], $this->authHeaders($buyer));
        $response->assertForbidden();
        $this->assertSame('submitted', $order->fresh()->status->value);
        $this->assertNull($order->fresh()->approved_by);
    }

    public function test_user_without_cancel_permission_cannot_cancel(): void
    {
        $buyer = $this->restrictedUser(['purchases.view', 'purchases.create', 'purchases.update']);

        $order = $this->makeOrder(PurchaseOrderStatus::Draft);

        $response = $this->postJson("/api/v1/purchase-orders/{$order->id}/cancel", [], $this->authHeaders($buyer));
        $response->assertForbidden();
        $this->assertSame('draft', $order->fresh()->status->value);
    }

    public function test_orders_are_scoped_to_the_users_companies(): void
    {
        $this->makeOrder(PurchaseOrderStatus::Draft);

        $otherCompany = Company::factory()->create();
        $foreignUser = $this->restrictedUser(['purchases.view']);
        $foreignUser->companies()->attach($otherCompany->id);

        // The viewer legitimately holds purchases.view inside the other
        // company, so the request is authorised but sees none of the orders.
        $foreignRole = Role::create([
            'company_id' => $otherCompany->id,
            'name' => 'viewer_'.uniqid(),
            'display_name' => 'Viewer',
        ]);
        $foreignRole->permissions()->sync(Permission::whereIn('name', ['purchases.view'])->pluck('id')->all());
        $foreignUser->roles()->attach($foreignRole->id);
        $foreignUser->clearPermissionCache($otherCompany->id);

        $response = $this->getJson('/api/v1/purchase-orders', $this->authHeaders($foreignUser, ['company_id' => $otherCompany->id]));

        $response->assertOk();
        $this->assertSame(0, $response->json('meta.total'));
    }
}
