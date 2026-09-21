<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseTransfer;
use App\Services\InventoryService;
use Tests\TestCase;

class StockOperationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->authenticatedUser([
            'inventory.view', 'inventory.adjust', 'inventory.opname',
            'inventory.transfer', 'inventory.approve',
        ]);

        // A second shed so a transfer has somewhere to go.
        $this->warehouseTwo = Warehouse::factory()->for($this->company)->for($this->branch)->create();
        $this->user->warehouses()->attach($this->warehouseTwo->id);

        $this->unit = Unit::factory()->for($this->company)->create();
        $this->product = $this->makeProduct();
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

    /**
     * Book real units onto a warehouse through the inventory engine so a
     * balance exists to adjust, count or transfer.
     */
    protected function seedStock(Product $product, string $quantity, ?Warehouse $warehouse = null): void
    {
        $warehouse ??= $this->warehouse;

        $this->inventory()->move(
            [
                'product_id' => $product->id,
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
            '1000'
        );
    }

    protected function onHand(Product $product, ?Warehouse $warehouse = null): string
    {
        return $this->inventory()->onHand($product->id, null, ($warehouse ?? $this->warehouse)->id);
    }

    protected function headers(): array
    {
        return $this->authHeaders($this->user);
    }

    /**
     * A second user inside the same business tree, holding only the listed
     * permissions. Reusing the existing company keeps the seeded products and
     * warehouses valid for this user's requests.
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
     * The adjustment factory has no item factory, and items carry no soft state,
     * so a line is written directly with the values the post needs.
     */
    protected function addAdjustmentItem(StockAdjustment $adjustment, Product $product, Unit $unit, string $quantity): StockAdjustmentItem
    {
        return StockAdjustmentItem::create([
            'stock_adjustment_id' => $adjustment->id,
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'quantity' => $quantity,
            'current_stock' => $this->onHand($product),
            'unit_cost' => '1000',
        ]);
    }

    //
    // Stock adjustments
    //

    public function test_adjustment_lifecycle_walks_to_posted_and_writes_the_ledger(): void
    {
        $this->seedStock($this->product, '100');

        $other = $this->makeProduct();
        $this->seedStock($other, '50');

        $response = $this->postJson('/api/v1/stock-adjustments', [
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'warehouse_id' => $this->warehouse->id,
            'adjustment_date' => now()->toDateString(),
            'adjustment_type' => 'increase',
            'reason' => 'found',
            'items' => [
                ['product_id' => $this->product->id, 'unit_id' => $this->unit->id, 'quantity' => '10', 'unit_cost' => '1000'],
                ['product_id' => $other->id, 'unit_id' => $this->unit->id, 'quantity' => '5', 'unit_cost' => '1000'],
            ],
        ], $this->headers());

        $response->assertCreated();
        // Seed stock writes reference documents of its own, so the adjustment
        // under test is resolved by id rather than by picking the first row.
        $adjustment = StockAdjustment::find($response->json('data.id'));
        $this->assertSame('draft', $adjustment->status->value);

        $this->postJson("/api/v1/stock-adjustments/{$adjustment->id}/submit", [], $this->headers())->assertOk();
        $this->assertSame('submitted', $adjustment->fresh()->status->value);

        $approve = $this->postJson("/api/v1/stock-adjustments/{$adjustment->id}/approve", [], $this->headers());
        $approve->assertOk();
        $this->assertSame('approved', $adjustment->fresh()->status->value);
        $this->assertSame($this->user->id, $adjustment->fresh()->approved_by);

        $this->postJson("/api/v1/stock-adjustments/{$adjustment->id}/post", [], $this->headers())->assertOk();

        $adjustment->refresh();
        $this->assertSame('posted', $adjustment->status->value);
        $this->assertNotNull($adjustment->posted_at);

        // Balances moved by exactly the adjusted quantities.
        $this->assertSame('110.000000', $this->onHand($this->product));
        $this->assertSame('55.000000', $this->onHand($other));

        // One signed ledger row per line, pointed at the document.
        $movements = StockMovement::query()
            ->where('reference_type', StockAdjustment::class)
            ->where('reference_id', $adjustment->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $movements);
        $this->assertSame(MovementType::AdjustmentIn->value, $movements[0]->movement_type->value);
        $this->assertSame('10.000000', (string) $movements[0]->quantity);
        $this->assertSame('110.000000', (string) $movements[0]->balance_after);
        $this->assertSame('5.000000', (string) $movements[1]->quantity);
        $this->assertSame('55.000000', (string) $movements[1]->balance_after);
    }

    public function test_posted_adjustment_cannot_be_edited_or_deleted(): void
    {
        $this->seedStock($this->product, '100');

        $adjustment = StockAdjustment::factory()
            ->for($this->company)
            ->for($this->warehouse)
            ->create([
                'adjustment_type' => 'increase',
                'reason' => 'found',
                'status' => 'approved',
            ]);

        $this->addAdjustmentItem($adjustment, $this->product, $this->unit, '10');

        $this->postJson("/api/v1/stock-adjustments/{$adjustment->id}/post", [], $this->headers())->assertOk();

        $update = $this->putJson("/api/v1/stock-adjustments/{$adjustment->id}", [
            'notes' => 'tampered',
        ], $this->headers());

        $update->assertStatus(422);
        $this->assertSame('posted', $adjustment->fresh()->status->value);
        $this->assertNull($adjustment->fresh()->notes);

        $delete = $this->deleteJson("/api/v1/stock-adjustments/{$adjustment->id}", [], $this->headers());
        $delete->assertStatus(422);

        $this->assertNotNull(StockAdjustment::find($adjustment->id));
    }

    public function test_out_of_order_transition_is_rejected(): void
    {
        $adjustment = StockAdjustment::factory()
            ->for($this->company)
            ->for($this->warehouse)
            ->create(['status' => 'draft', 'adjustment_type' => 'increase', 'reason' => 'found']);

        $response = $this->postJson("/api/v1/stock-adjustments/{$adjustment->id}/approve", [], $this->headers());

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('status');
        $this->assertStringContainsString('draft', $response->json('errors.status.0'));
        $this->assertSame('draft', $adjustment->fresh()->status->value);
    }

    public function test_decrease_adjustment_cannot_drive_stock_negative(): void
    {
        $this->seedStock($this->product, '10');

        $adjustment = StockAdjustment::factory()
            ->for($this->company)
            ->for($this->warehouse)
            ->create([
                'adjustment_type' => 'decrease',
                'reason' => 'damage',
                'status' => 'approved',
            ]);

        $this->addAdjustmentItem($adjustment, $this->product, $this->unit, '50');

        $response = $this->postJson("/api/v1/stock-adjustments/{$adjustment->id}/post", [], $this->headers());

        $response->assertStatus(422);
        $this->assertSame('approved', $adjustment->fresh()->status->value);
        $this->assertSame('10.000000', $this->onHand($this->product));
        $this->assertSame(0, StockMovement::query()
            ->where('reference_type', StockAdjustment::class)
            ->where('reference_id', $adjustment->id)
            ->count());
    }

    public function test_user_without_adjust_permission_is_forbidden(): void
    {
        $viewer = $this->restrictedUser(['inventory.view']);

        $response = $this->postJson('/api/v1/stock-adjustments', [
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'adjustment_date' => now()->toDateString(),
            'adjustment_type' => 'increase',
            'reason' => 'found',
            'items' => [
                ['product_id' => $this->product->id, 'unit_id' => $this->unit->id, 'quantity' => '1'],
            ],
        ], $this->authHeaders($viewer));

        $response->assertForbidden();
        $this->assertSame(0, StockAdjustment::count());
    }

    //
    // Stock opnames
    //

    public function test_opname_posts_in_and_out_movements_and_skips_matching_lines(): void
    {
        $surplus = $this->makeProduct();
        $short = $this->makeProduct();
        $exact = $this->makeProduct();

        foreach ([$surplus, $short, $exact] as $product) {
            $this->seedStock($product, '100');
        }

        $response = $this->postJson('/api/v1/stock-opnames', [
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'warehouse_id' => $this->warehouse->id,
            'opname_date' => now()->toDateString(),
            'items' => [
                ['product_id' => $surplus->id, 'unit_id' => $this->unit->id, 'counted_quantity' => '120', 'unit_cost' => '1000'],
                ['product_id' => $short->id, 'unit_id' => $this->unit->id, 'counted_quantity' => '80', 'unit_cost' => '1000'],
                ['product_id' => $exact->id, 'unit_id' => $this->unit->id, 'counted_quantity' => '100', 'unit_cost' => '1000'],
            ],
        ], $this->headers());

        $response->assertCreated();
        $opname = StockOpname::first();

        // The system side of the sheet is frozen when counting opens.
        $this->postJson("/api/v1/stock-opnames/{$opname->id}/count", [], $this->headers())->assertOk();
        $this->assertSame('counting', $opname->fresh()->status->value);

        foreach ([$surplus, $short, $exact] as $product) {
            $row = $opname->fresh()->items->firstWhere('product_id', $product->id);
            $this->assertSame('100.000000', (string) $row->system_quantity);
        }

        $this->postJson("/api/v1/stock-opnames/{$opname->id}/review", [], $this->headers())->assertOk();
        $this->postJson("/api/v1/stock-opnames/{$opname->id}/approve", [], $this->headers())->assertOk();
        $this->postJson("/api/v1/stock-opnames/{$opname->id}/post", [], $this->headers())->assertOk();

        $this->assertSame('posted', $opname->fresh()->status->value);

        // +20 booked in, -20 booked out, the matching line wrote nothing.
        $this->assertSame('120.000000', $this->onHand($surplus));
        $this->assertSame('80.000000', $this->onHand($short));
        $this->assertSame('100.000000', $this->onHand($exact));

        $movements = StockMovement::query()
            ->where('reference_type', StockOpname::class)
            ->where('reference_id', $opname->id)
            ->get();

        $this->assertCount(2, $movements);
        $this->assertSame(MovementType::AdjustmentIn->value, $movements->firstWhere('product_id', $surplus->id)->movement_type->value);
        $this->assertSame(MovementType::AdjustmentOut->value, $movements->firstWhere('product_id', $short->id)->movement_type->value);
    }

    public function test_posted_opname_cannot_be_edited(): void
    {
        $this->seedStock($this->product, '100');

        $opname = StockOpname::factory()
            ->for($this->company)
            ->for($this->warehouse)
            ->create(['status' => 'posted', 'posted_at' => now()]);

        $response = $this->putJson("/api/v1/stock-opnames/{$opname->id}", [
            'notes' => 'tampered',
        ], $this->headers());

        $response->assertStatus(422);
        $this->assertNull($opname->fresh()->notes);
    }

    //
    // Warehouse transfers
    //

    protected function createTransfer(Product $product, string $quantity): WarehouseTransfer
    {
        $response = $this->postJson('/api/v1/warehouse-transfers', [
            'company_id' => $this->company->id,
            'transfer_date' => now()->toDateString(),
            'from_warehouse_id' => $this->warehouse->id,
            'to_warehouse_id' => $this->warehouseTwo->id,
            'items' => [
                ['product_id' => $product->id, 'unit_id' => $this->unit->id, 'quantity' => $quantity],
            ],
        ], $this->headers());

        $response->assertCreated();

        return WarehouseTransfer::find($response->json('data.id'));
    }

    protected function advance(WarehouseTransfer $transfer, array $stages): void
    {
        foreach ($stages as $stage) {
            $endpoint = match ($stage) {
                'submit' => "/api/v1/warehouse-transfers/{$transfer->id}/submit",
                'approve' => "/api/v1/warehouse-transfers/{$transfer->id}/approve",
                'ship' => "/api/v1/warehouse-transfers/{$transfer->id}/ship",
                default => throw new \InvalidArgumentException("Unknown stage: {$stage}"),
            };

            $this->postJson($endpoint, [], $this->headers())->assertOk();
        }
    }

    public function test_transfer_ship_debits_source_and_receive_credits_destination(): void
    {
        $this->seedStock($this->product, '100');

        $transfer = $this->createTransfer($this->product, '30');
        $this->advance($transfer, ['submit', 'approve', 'ship']);

        $this->assertSame('shipped', $transfer->fresh()->status->value);
        $this->assertSame('70.000000', $this->onHand($this->product));

        $out = StockMovement::query()
            ->where('reference_type', WarehouseTransfer::class)
            ->where('reference_id', $transfer->id)
            ->firstOrFail();
        $this->assertSame(MovementType::TransferOut->value, $out->movement_type->value);
        $this->assertSame('-30.000000', (string) $out->quantity);

        // The destination is untouched until the goods actually arrive.
        $this->assertSame('0.000000', $this->onHand($this->product, $this->warehouseTwo));

        $receive = $this->postJson("/api/v1/warehouse-transfers/{$transfer->id}/receive", [
            'items' => [['id' => $transfer->fresh()->items->first()->id, 'quantity_received' => '30']],
        ], $this->headers());
        $receive->assertOk();
        $receive->assertJsonPath('message', 'Warehouse transfer fully received');

        $transfer->refresh();
        $this->assertSame('received', $transfer->status->value);
        $this->assertSame('30.000000', $this->onHand($this->product, $this->warehouseTwo));

        $this->postJson("/api/v1/warehouse-transfers/{$transfer->id}/complete", [], $this->headers())->assertOk();
        $this->assertSame('completed', $transfer->fresh()->status->value);
    }

    public function test_partial_receive_keeps_the_transfer_open(): void
    {
        $this->seedStock($this->product, '100');

        $transfer = $this->createTransfer($this->product, '30');
        $this->advance($transfer, ['submit', 'approve', 'ship']);

        $itemId = $transfer->fresh()->items->first()->id;

        $first = $this->postJson("/api/v1/warehouse-transfers/{$transfer->id}/receive", [
            'items' => [['id' => $itemId, 'quantity_received' => '10']],
        ], $this->headers());
        $first->assertOk();
        $first->assertJsonPath('message', 'Warehouse transfer partially received');

        // Not everything has landed, so the transfer stays open for the rest.
        $transfer->refresh();
        $this->assertSame('shipped', $transfer->status->value);
        $this->assertSame('10.000000', (string) $transfer->items->first()->quantity_received);
        $this->assertSame('10.000000', $this->onHand($this->product, $this->warehouseTwo));

        $second = $this->postJson("/api/v1/warehouse-transfers/{$transfer->id}/receive", [
            'items' => [['id' => $itemId, 'quantity_received' => '20']],
        ], $this->headers());
        $second->assertOk();

        $transfer->refresh();
        $this->assertSame('received', $transfer->status->value);
        $this->assertSame('30.000000', (string) $transfer->items->first()->quantity_received);
        $this->assertSame('30.000000', $this->onHand($this->product, $this->warehouseTwo));
    }

    public function test_cancel_is_rejected_once_shipped(): void
    {
        $this->seedStock($this->product, '100');

        $transfer = $this->createTransfer($this->product, '10');
        $this->advance($transfer, ['submit', 'approve', 'ship']);

        $response = $this->postJson("/api/v1/warehouse-transfers/{$transfer->id}/cancel", [], $this->headers());

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('status');
        $this->assertSame('shipped', $transfer->fresh()->status->value);
    }

    public function test_cancel_from_draft_is_allowed(): void
    {
        $this->seedStock($this->product, '100');

        $transfer = $this->createTransfer($this->product, '10');

        $response = $this->postJson("/api/v1/warehouse-transfers/{$transfer->id}/cancel", [], $this->headers());

        $response->assertOk();
        $this->assertSame('cancelled', $transfer->fresh()->status->value);
        // Nothing had left, so stock is untouched.
        $this->assertSame('100.000000', $this->onHand($this->product));
    }

    public function test_transfer_exceeding_available_stock_is_rejected(): void
    {
        $this->seedStock($this->product, '10');

        $transfer = $this->createTransfer($this->product, '50');
        $this->advance($transfer, ['submit', 'approve']);

        $response = $this->postJson("/api/v1/warehouse-transfers/{$transfer->id}/ship", [], $this->headers());

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('items');
        $this->assertSame('approved', $transfer->fresh()->status->value);
        $this->assertSame('10.000000', $this->onHand($this->product));
        $this->assertSame(0, StockMovement::query()
            ->where('reference_type', WarehouseTransfer::class)
            ->where('reference_id', $transfer->id)
            ->count());
    }

    public function test_transfer_ship_succeeds_when_negative_stock_is_allowed(): void
    {
        $this->seedStock($this->product, '10');
        $this->product->update(['allow_negative_stock' => true]);

        $transfer = $this->createTransfer($this->product, '50');
        $this->advance($transfer, ['submit', 'approve', 'ship']);

        $this->assertSame('shipped', $transfer->fresh()->status->value);
        $this->assertSame('-40.000000', $this->onHand($this->product));
    }

    public function test_user_without_transfer_permission_is_forbidden(): void
    {
        $viewer = $this->restrictedUser(['inventory.view']);

        $response = $this->postJson('/api/v1/warehouse-transfers', [
            'company_id' => $this->company->id,
            'transfer_date' => now()->toDateString(),
            'from_warehouse_id' => $this->warehouse->id,
            'to_warehouse_id' => $this->warehouseTwo->id,
            'items' => [
                ['product_id' => $this->product->id, 'unit_id' => $this->unit->id, 'quantity' => '1'],
            ],
        ], $this->authHeaders($viewer));

        $response->assertForbidden();
        $this->assertSame(0, WarehouseTransfer::count());
    }
}
