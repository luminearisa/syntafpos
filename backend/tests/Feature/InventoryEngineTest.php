<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\InventoryService;
use Tests\TestCase;

class InventoryEngineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->branch = Branch::factory()->for($this->company)->create();
        $this->warehouse = Warehouse::factory()->for($this->company)->for($this->branch)->create();
        $this->unit = Unit::factory()->for($this->company)->create();
        $this->product = Product::factory()->for($this->company)->create([
            'default_unit_id' => $this->unit->id,
        ]);

        $this->user = User::factory()->create();
        $this->user->companies()->attach($this->company->id);
    }

    protected function where(): array
    {
        return [
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'warehouse_id' => $this->warehouse->id,
            'location_id' => null,
        ];
    }

    protected function line(): array
    {
        return [
            'product_id' => $this->product->id,
            'product_variant_id' => null,
            'unit_id' => $this->unit->id,
        ];
    }

    protected function service(): InventoryService
    {
        return $this->app->make(InventoryService::class);
    }

    public function test_movement_records_signed_quantity_and_balance(): void
    {
        $reference = StockAdjustment::factory()->for($this->company)->for($this->warehouse)->create();

        $this->service()->move(
            array_merge($this->line(), ['quantity' => '10']),
            MovementType::Opening,
            $this->where(),
            $reference,
            '10000'
        );

        $movement = StockMovement::first();
        $this->assertSame('10.000000', (string) $movement->quantity);
        $this->assertSame('10.000000', (string) $movement->balance_after);
        $this->assertSame('10000.0000', (string) $movement->unit_cost);
        $this->assertSame('100000.0000', (string) $movement->total_cost);
    }

    public function test_weighted_average_cost_updates_on_receipt(): void
    {
        $reference = StockAdjustment::factory()->for($this->company)->for($this->warehouse)->create();
        $service = $this->service();
        $where = $this->where();
        $line = $this->line();

        $service->move(array_merge($line, ['quantity' => '100']), MovementType::Opening, $where, $reference, '10000');
        $service->move(array_merge($line, ['quantity' => '50']), MovementType::Purchase, $where, $reference, '12000');

        // (100 x 10.000 + 50 x 12.000) / 150 = 10.666.67
        $balance = $this->product->stockBalances()->first();
        $this->assertSame('150.000000', (string) $balance->on_hand);
        $this->assertSame('10666.6667', (string) $balance->average_cost);
        $this->assertSame('12000.0000', (string) $balance->last_cost);
    }

    public function test_issue_uses_average_cost_and_reduces_balance(): void
    {
        $reference = StockAdjustment::factory()->for($this->company)->for($this->warehouse)->create();
        $service = $this->service();
        $where = $this->where();
        $line = $this->line();

        $service->move(array_merge($line, ['quantity' => '100']), MovementType::Opening, $where, $reference, '10000');
        $service->move(array_merge($line, ['quantity' => '30']), MovementType::AdjustmentOut, $where, $reference, '0');

        $balance = $this->product->stockBalances()->first();
        $this->assertSame('70.000000', (string) $balance->on_hand);
        // Average cost is untouched by an issue.
        $this->assertSame('10000.0000', (string) $balance->average_cost);

        $issue = StockMovement::latest('id')->first();
        $this->assertSame('-30.000000', (string) $issue->quantity);
        $this->assertSame('70.000000', (string) $issue->balance_after);
        $this->assertSame('10000.0000', (string) $issue->unit_cost);
    }

    public function test_issue_to_zero_leaves_no_residual_cost(): void
    {
        $reference = StockAdjustment::factory()->for($this->company)->for($this->warehouse)->create();
        $service = $this->service();
        $where = $this->where();
        $line = $this->line();

        $service->move(array_merge($line, ['quantity' => '10']), MovementType::Opening, $where, $reference, '5000');
        $service->move(array_merge($line, ['quantity' => '10']), MovementType::AdjustmentOut, $where, $reference, '0');
        // Restocking after an empty balance starts a fresh average.
        $service->move(array_merge($line, ['quantity' => '10']), MovementType::Purchase, $where, $reference, '8000');

        $balance = $this->product->stockBalances()->first();
        $this->assertSame('10.000000', (string) $balance->on_hand);
        $this->assertSame('8000.0000', (string) $balance->average_cost);
    }

    public function test_negative_stock_is_refused_by_default(): void
    {
        $reference = StockAdjustment::factory()->for($this->company)->for($this->warehouse)->create();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot go below zero');

        $this->service()->move(
            array_merge($this->line(), ['quantity' => '5']),
            MovementType::AdjustmentOut,
            $this->where(),
            $reference,
            '0'
        );
    }

    public function test_negative_stock_allowed_when_product_opted_in(): void
    {
        $this->product->update(['allow_negative_stock' => true]);
        $reference = StockAdjustment::factory()->for($this->company)->for($this->warehouse)->create();

        $movement = $this->service()->move(
            array_merge($this->line(), ['quantity' => '5']),
            MovementType::AdjustmentOut,
            $this->where(),
            $reference,
            '0'
        );

        $this->assertSame('-5.000000', (string) $movement->balance_after);
    }

    public function test_reserve_and_release_moves_available_not_on_hand(): void
    {
        $reference = StockAdjustment::factory()->for($this->company)->for($this->warehouse)->create();
        $service = $this->service();
        $where = $this->where();
        $line = $this->line();

        $service->move(array_merge($line, ['quantity' => '100']), MovementType::Opening, $where, $reference, '1000');
        $service->reserve($line, $where, $reference, '30');

        $balance = $this->product->stockBalances()->first();
        $this->assertSame('100.000000', (string) $balance->on_hand);
        $this->assertSame('30.000000', (string) $balance->reserved);
        $this->assertSame('70.000000', $balance->available());

        $service->release($line, $where, '10');
        $balance->refresh();
        $this->assertSame('20.000000', (string) $balance->reserved);
        $this->assertSame('80.000000', $balance->available());
    }

    public function test_reserve_cannot_exceed_available(): void
    {
        $reference = StockAdjustment::factory()->for($this->company)->for($this->warehouse)->create();
        $service = $this->service();

        $this->expectException(\RuntimeException::class);
        $service->reserve($this->line(), $this->where(), $reference, '1');
    }

    public function test_movement_is_tied_to_its_reference_document(): void
    {
        $reference = StockAdjustment::factory()->for($this->company)->for($this->warehouse)->create();

        $movement = $this->service()->move(
            array_merge($this->line(), ['quantity' => '10']),
            MovementType::Opening,
            $this->where(),
            $reference,
            '1000'
        );

        $this->assertSame(StockAdjustment::class, $movement->reference_type);
        $this->assertSame($reference->id, $movement->reference_id);
    }

    public function test_on_hand_sums_across_locations(): void
    {
        $locationA = WarehouseLocation::factory()->for($this->warehouse)->create();
        $locationB = WarehouseLocation::factory()->for($this->warehouse)->create();
        $reference = StockAdjustment::factory()->for($this->company)->for($this->warehouse)->create();
        $service = $this->service();
        $line = $this->line();

        $service->move(array_merge($line, ['quantity' => '40']), MovementType::Opening, array_merge($this->where(), ['location_id' => $locationA->id]), $reference, '1000');
        $service->move(array_merge($line, ['quantity' => '60']), MovementType::Opening, array_merge($this->where(), ['location_id' => $locationB->id]), $reference, '1000');

        $this->assertSame('100.000000', $service->onHand($this->product->id, null, $this->warehouse->id));
        $this->assertSame('40.000000', $service->onHand($this->product->id, null, $this->warehouse->id, $locationA->id));
    }
}
