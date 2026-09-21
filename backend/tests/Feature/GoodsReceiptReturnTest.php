<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Permission;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseReturn;
use App\Models\Role;
use App\Models\StockAdjustment;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Services\SettingsService;
use Tests\TestCase;

class GoodsReceiptReturnTest extends TestCase
{
    protected Unit $unit;

    protected Supplier $supplier;

    protected Product $product;

    protected Warehouse $warehouseTwo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->authenticatedUser([
            'purchases.view', 'purchases.receive', 'inventory.view',
        ]);

        $this->unit = Unit::factory()->for($this->company)->create();
        $this->supplier = Supplier::factory()->for($this->company)->create();
        $this->product = $this->makeProduct();
        $this->warehouseTwo = Warehouse::factory()->for($this->company)->for($this->branch)->create();
    }

    protected function makeProduct(): Product
    {
        return Product::factory()->for($this->company)->create([
            'default_unit_id' => $this->unit->id,
        ]);
    }

    protected function inventory(): InventoryService
    {
        return $this->app->make(InventoryService::class);
    }

    protected function headers(): array
    {
        return $this->authHeaders($this->user);
    }

    protected function onHand(?Warehouse $warehouse = null): string
    {
        return $this->inventory()->onHand($this->product->id, null, ($warehouse ?? $this->warehouse)->id);
    }

    /**
     * Raise an order with lines, in a status open to receiving.
     *
     * @param  array<int, array{quantity: string, unit_price?: string}>  $lines
     */
    protected function purchaseOrder(array $lines): PurchaseOrder
    {
        $order = PurchaseOrder::factory()
            ->for($this->company)
            ->for($this->warehouse)
            ->for($this->supplier)
            ->create(['status' => 'sent']);

        foreach ($lines as $line) {
            $price = $line['unit_price'] ?? '1000';

            PurchaseOrderItem::create([
                'purchase_order_id' => $order->id,
                'product_id' => $this->product->id,
                'unit_id' => $this->unit->id,
                'quantity' => $line['quantity'],
                'unit_price' => $price,
                'net_price' => $price,
                'subtotal' => bcmul($line['quantity'], $price, 4),
            ]);
        }

        return $order->fresh();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    protected function receiptPayload(PurchaseOrder $order, array $items): array
    {
        return [
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $order->id,
            'receipt_date' => now()->toDateString(),
            'items' => $items,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    protected function returnPayload(array $items, ?int $receiptId = null, ?int $orderId = null, ?Warehouse $warehouse = null): array
    {
        return [
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'warehouse_id' => ($warehouse ?? $this->warehouse)->id,
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $orderId,
            'goods_receipt_id' => $receiptId,
            'return_date' => now()->toDateString(),
            'reason' => 'damaged',
            'items' => $items,
        ];
    }

    protected function balance(?Warehouse $warehouse = null): StockBalance
    {
        return StockBalance::query()
            ->where('company_id', $this->company->id)
            ->where('warehouse_id', ($warehouse ?? $this->warehouse)->id)
            ->where('product_id', $this->product->id)
            ->where('unit_id', $this->unit->id)
            ->firstOrFail();
    }

    /**
     * Book real units onto a warehouse through the inventory engine so a
     * balance exists for a return to draw on.
     */
    protected function seedStock(string $quantity, string $cost = '1000', ?Warehouse $warehouse = null): void
    {
        $warehouse ??= $this->warehouse;

        $this->inventory()->move(
            [
                'product_id' => $this->product->id,
                'product_variant_id' => null,
                'unit_id' => $this->unit->id,
                'quantity' => $quantity,
            ],
            MovementType::Opening,
            [
                'company_id' => $this->company->id,
                'branch_id' => $this->branch->id,
                'warehouse_id' => $warehouse->id,
                'location_id' => null,
            ],
            StockAdjustment::factory()->for($this->company)->for($warehouse)->create(),
            $cost
        );
    }

    /**
     * Post a receipt for one line of an order and return the goods receipt id.
     */
    protected function receive(PurchaseOrder $order, string $quantity, string $unitPrice = '1000'): int
    {
        $poItem = $order->items->first();

        return $this->postJson('/api/v1/goods-receipts', $this->receiptPayload($order, [
            [
                'purchase_order_item_id' => $poItem->id,
                'product_id' => $this->product->id,
                'unit_id' => $this->unit->id,
                'quantity_received' => $quantity,
            ],
        ]), $this->headers())
            ->assertCreated()
            ->json('data.id');
    }

    protected function postReceipt(int $receiptId): void
    {
        $this->postJson("/api/v1/goods-receipts/{$receiptId}/post", [], $this->headers())->assertOk();
    }

    //
    // Goods receipts
    //

    public function test_full_receiving_marks_the_order_received_and_books_stock(): void
    {
        $order = $this->purchaseOrder([['quantity' => '100', 'unit_price' => '1000']]);

        $receiptId = $this->receive($order, '100');
        $this->postReceipt($receiptId);

        // The order closed out and its line carries what landed.
        $this->assertSame('received', $order->fresh()->status->value);
        $this->assertSame('100.000000', (string) $order->fresh()->items->first()->quantity_received);

        // Stock moved at the receipt's cost, which became the weighted average.
        $this->assertSame('100.000000', $this->onHand());
        $this->assertSame('1000.0000', $this->balance()->average_cost);
        $this->assertSame('1000.0000', $this->balance()->last_cost);

        $movement = StockMovement::query()
            ->where('reference_type', 'App\\Models\\GoodsReceipt')
            ->where('reference_id', $receiptId)
            ->firstOrFail();

        $this->assertSame(MovementType::Purchase->value, $movement->movement_type->value);
        $this->assertSame('100.000000', $movement->quantity);
        $this->assertSame('1000.0000', $movement->unit_cost);
    }

    public function test_partial_receiving_leaves_the_order_partially_received(): void
    {
        $order = $this->purchaseOrder([['quantity' => '100']]);

        $this->postReceipt($this->receive($order, '70'));

        $this->assertSame('partially_received', $order->fresh()->status->value);
        $this->assertSame('70.000000', (string) $order->fresh()->items->first()->quantity_received);
        $this->assertSame('70.000000', $this->onHand());
    }

    public function test_receiving_accumulates_across_receipts(): void
    {
        $order = $this->purchaseOrder([['quantity' => '100']]);

        $this->postReceipt($this->receive($order, '70'));
        $this->assertSame('partially_received', $order->fresh()->status->value);

        // A second receipt lands the remainder and closes the order.
        $this->postReceipt($this->receive($order, '30'));

        $order->refresh();
        $this->assertSame('received', $order->status->value);
        $this->assertSame('100.000000', (string) $order->items->first()->quantity_received);
        $this->assertSame('100.000000', $this->onHand());
    }

    public function test_over_receiving_is_rejected_when_the_setting_is_off(): void
    {
        $order = $this->purchaseOrder([['quantity' => '100']]);

        $this->postJson('/api/v1/goods-receipts', $this->receiptPayload($order, [
            [
                'purchase_order_item_id' => $order->items->first()->id,
                'product_id' => $this->product->id,
                'unit_id' => $this->unit->id,
                'quantity_received' => '120',
            ],
        ]), $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.quantity_received']);

        $this->assertSame('0.000000', $this->onHand());
        $this->assertSame('sent', $order->fresh()->status->value);
    }

    public function test_over_receiving_is_allowed_when_the_setting_is_on(): void
    {
        app(SettingsService::class)->set('inventory.allow_over_receiving', true, $this->company->id);

        $order = $this->purchaseOrder([['quantity' => '100']]);
        $this->postReceipt($this->receive($order, '120'));

        $this->assertSame('120.000000', $this->onHand());
        $this->assertSame('received', $order->fresh()->status->value);
    }

    public function test_a_standalone_receipt_posts_stock_without_an_order(): void
    {
        $response = $this->postJson('/api/v1/goods-receipts', [
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->supplier->id,
            'receipt_date' => now()->toDateString(),
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'unit_id' => $this->unit->id,
                    'quantity_received' => '25',
                    'unit_cost' => '800',
                ],
            ],
        ], $this->headers());

        $receiptId = $response->assertCreated()->json('data.id');
        $this->assertNull($response->json('data.purchase_order_id'));

        $this->postReceipt($receiptId);

        $this->assertSame('25.000000', $this->onHand());
        $this->assertSame('800.0000', $this->balance()->average_cost);
    }

    public function test_unit_cost_defaults_to_unit_price_when_not_stated(): void
    {
        $order = $this->purchaseOrder([['quantity' => '10', 'unit_price' => '1500']]);

        $this->postJson('/api/v1/goods-receipts', $this->receiptPayload($order, [
            [
                'purchase_order_item_id' => $order->items->first()->id,
                'product_id' => $this->product->id,
                'unit_id' => $this->unit->id,
                'quantity_received' => '10',
            ],
        ]), $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.items.0.unit_price', '1500.0000')
            ->assertJsonPath('data.items.0.unit_cost', '1500.0000')
            ->assertJsonPath('data.items.0.quantity_ordered', '10.000000');
    }

    public function test_a_posted_receipt_cannot_be_edited_or_deleted(): void
    {
        $order = $this->purchaseOrder([['quantity' => '10']]);
        $receiptId = $this->receive($order, '10');
        $this->postReceipt($receiptId);

        $this->putJson("/api/v1/goods-receipts/{$receiptId}", [
            'notes' => 'changed after the fact',
        ], $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->deleteJson("/api/v1/goods-receipts/{$receiptId}", [], $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->assertDatabaseHas('goods_receipts', ['id' => $receiptId, 'status' => 'posted']);
    }

    public function test_posting_twice_is_refused(): void
    {
        $order = $this->purchaseOrder([['quantity' => '10']]);
        $receiptId = $this->receive($order, '10');

        $this->postReceipt($receiptId);
        $this->postJson("/api/v1/goods-receipts/{$receiptId}/post", [], $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        // The second post moved nothing extra.
        $this->assertSame('10.000000', $this->onHand());
    }

    //
    // Purchase returns
    //

    public function test_a_purchase_return_reduces_stock_and_cannot_exceed_what_was_received(): void
    {
        $order = $this->purchaseOrder([['quantity' => '100']]);
        $receiptId = $this->receive($order, '100');
        $this->postReceipt($receiptId);

        $grItem = GoodsReceiptItem::query()
            ->where('goods_receipt_id', $receiptId)
            ->where('purchase_order_item_id', $order->items->first()->id)
            ->firstOrFail();

        $returnId = $this->postJson('/api/v1/purchase-returns', $this->returnPayload([
            [
                'goods_receipt_item_id' => $grItem->id,
                'product_id' => $this->product->id,
                'unit_id' => $this->unit->id,
                'quantity' => '40',
            ],
        ], $receiptId, $order->id), $this->headers())
            ->assertCreated()
            ->json('data.id');

        $this->postJson("/api/v1/purchase-returns/{$returnId}/post", [], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.status', 'posted');

        $this->assertSame('60.000000', $this->onHand());

        $movement = StockMovement::query()
            ->where('reference_type', 'App\\Models\\PurchaseReturn')
            ->where('reference_id', $returnId)
            ->firstOrFail();

        $this->assertSame(MovementType::PurchaseReturn->value, $movement->movement_type->value);
        $this->assertSame('-40.000000', $movement->quantity);

        // The document total is recomputed server side from the lines.
        $this->assertSame('40000.0000', (string) PurchaseReturn::find($returnId)->total_amount);

        // Returning more than is still returnable is refused before anything moves.
        $this->postJson('/api/v1/purchase-returns', $this->returnPayload([
            [
                'goods_receipt_item_id' => $grItem->id,
                'product_id' => $this->product->id,
                'unit_id' => $this->unit->id,
                'quantity' => '61',
            ],
        ], $receiptId, $order->id), $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.quantity']);

        $this->assertSame('60.000000', $this->onHand());
    }

    public function test_a_return_beyond_on_hand_is_rejected(): void
    {
        $order = $this->purchaseOrder([['quantity' => '100']]);
        $receiptId = $this->receive($order, '100');
        $this->postReceipt($receiptId);

        $grItem = GoodsReceiptItem::query()
            ->where('goods_receipt_id', $receiptId)
            ->where('purchase_order_item_id', $order->items->first()->id)
            ->firstOrFail();

        // The goods landed in the receiving warehouse, but the return is booked
        // out of a shed that holds nothing of this product.
        $returnId = $this->postJson('/api/v1/purchase-returns', $this->returnPayload([
            [
                'goods_receipt_item_id' => $grItem->id,
                'product_id' => $this->product->id,
                'unit_id' => $this->unit->id,
                'quantity' => '50',
            ],
        ], $receiptId, $order->id, $this->warehouseTwo), $this->headers())
            ->assertCreated()
            ->json('data.id');

        $this->postJson("/api/v1/purchase-returns/{$returnId}/post", [], $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items']);

        // Nothing moved and the document stayed a draft.
        $this->assertSame('100.000000', $this->onHand());
        $this->assertSame('0.000000', $this->onHand($this->warehouseTwo));
        $this->assertSame('draft', PurchaseReturn::find($returnId)->status->value);
    }

    public function test_a_purchase_return_without_a_reason_uses_the_column_default(): void
    {
        $order = $this->purchaseOrder([['quantity' => '100']]);
        $receiptId = $this->receive($order, '100');
        $this->postReceipt($receiptId);

        $grItem = GoodsReceiptItem::query()
            ->where('goods_receipt_id', $receiptId)
            ->where('purchase_order_item_id', $order->items->first()->id)
            ->firstOrFail();

        // `reason` is optional on the request but NOT NULL on the table, so the
        // controller owes the caller the column default instead of writing null.
        $payload = $this->returnPayload([
            [
                'goods_receipt_item_id' => $grItem->id,
                'product_id' => $this->product->id,
                'unit_id' => $this->unit->id,
                'quantity' => '10',
            ],
        ], $receiptId, $order->id);
        unset($payload['reason']);

        $returnId = $this->postJson('/api/v1/purchase-returns', $payload, $this->headers())
            ->assertCreated()
            ->json('data.id');

        $this->assertSame('other', PurchaseReturn::find($returnId)->reason);
    }

    public function test_the_receive_permission_is_required_to_post(): void
    {
        // The document is raised through the models rather than the API: a
        // request made as the authorised user first would resolve the auth
        // guard for the rest of the process, masking the permission boundary.
        $order = $this->purchaseOrder([['quantity' => '10']]);

        $receipt = GoodsReceipt::factory()
            ->for($this->company)
            ->for($this->warehouse)
            ->for($this->supplier)
            ->for($order)
            ->create(['status' => 'draft', 'posted_at' => null]);

        GoodsReceiptItem::create([
            'goods_receipt_id' => $receipt->id,
            'purchase_order_item_id' => $order->items->first()->id,
            'product_id' => $this->product->id,
            'unit_id' => $this->unit->id,
            'quantity_ordered' => '10',
            'quantity_received' => '10',
            'unit_price' => '1000',
            'unit_cost' => '1000',
        ]);

        $restricted = $this->restrictedUser(['purchases.view']);

        $this->postJson("/api/v1/goods-receipts/{$receipt->id}/post", [], $this->authHeaders($restricted))
            ->assertForbidden();

        $this->postJson('/api/v1/purchase-returns', $this->returnPayload([
            [
                'product_id' => $this->product->id,
                'unit_id' => $this->unit->id,
                'quantity' => '1',
            ],
        ]), $this->authHeaders($restricted))
            ->assertForbidden();

        $this->assertSame('0.000000', $this->onHand());
        $this->assertSame('draft', $receipt->fresh()->status->value);
        $this->assertSame(0, StockMovement::count());
    }

    /**
     * A second user inside the same business tree, holding only the listed
     * permissions.
     */
    protected function restrictedUser(array $permissions): User
    {
        $user = User::factory()->create();
        $user->companies()->attach($this->company->id);
        $user->branches()->attach($this->branch->id);
        $user->warehouses()->attach($this->warehouse->id);
        $user->warehouses()->attach($this->warehouseTwo->id);

        $role = Role::create([
            'company_id' => $this->company->id,
            'name' => 'restricted_'.uniqid(),
            'display_name' => 'Restricted',
        ]);

        $role->permissions()->sync(
            Permission::whereIn('name', $permissions)->pluck('id')->all()
        );

        $user->roles()->attach($role->id);
        $user->clearPermissionCache($this->company->id);

        return $user;
    }
}
