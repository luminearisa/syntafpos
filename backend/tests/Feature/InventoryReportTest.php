<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseTransfer;
use App\Models\WarehouseTransferItem;
use App\Services\InventoryService;
use App\Support\DecimalMath;
use Tests\TestCase;

class InventoryReportTest extends TestCase
{
    protected Unit $unit;

    protected Category $category;

    protected Brand $brand;

    protected Product $product;

    protected Product $otherProduct;

    protected Warehouse $warehouseTwo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->authenticatedUser(['reports.inventory', 'inventory.view']);

        $this->unit = Unit::factory()->for($this->company)->create();
        $this->category = Category::factory()->for($this->company)->create();
        $this->brand = Brand::factory()->for($this->company)->create();
        $this->warehouseTwo = Warehouse::factory()->for($this->company)->for($this->branch)->create();

        $this->product = $this->makeProduct('AAA', '5');
        $this->otherProduct = $this->makeProduct('BBB', '20');
    }

    protected function makeProduct(string $sku, string $reorderPoint): Product
    {
        return Product::factory()->for($this->company)->create([
            'sku' => $sku,
            'default_unit_id' => $this->unit->id,
            'category_id' => $this->category->id,
            'brand_id' => $this->brand->id,
            'reorder_point' => $reorderPoint,
            'reorder_quantity' => '12',
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

    /**
     * Book real units onto a warehouse through the inventory engine, so the
     * balances and movements the reports read are genuine ledger output.
     */
    protected function seedStock(string $quantity, string $cost, ?Warehouse $warehouse = null): void
    {
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
                'warehouse_id' => ($warehouse ?? $this->warehouse)->id,
                'location_id' => null,
            ],
            StockAdjustment::factory()->for($this->company)->for($warehouse ?? $this->warehouse)->create(),
            $cost,
            $this->user->id
        );
    }

    protected function seedOutgoing(string $quantity, ?Warehouse $warehouse = null): void
    {
        $this->inventory()->move(
            [
                'product_id' => $this->product->id,
                'product_variant_id' => null,
                'unit_id' => $this->unit->id,
                'quantity' => $quantity,
            ],
            MovementType::AdjustmentOut,
            [
                'company_id' => $this->company->id,
                'branch_id' => $this->branch->id,
                'warehouse_id' => ($warehouse ?? $this->warehouse)->id,
                'location_id' => null,
            ],
            StockAdjustment::factory()->for($this->company)->for($warehouse ?? $this->warehouse)->create(),
            '0',
            $this->user->id
        );
    }

    //
    // Stock summary
    //

    public function test_stock_summary_aggregates_balances_across_warehouses(): void
    {
        $this->seedStock('10', '1000');
        $this->seedStock('5', '1000', $this->warehouseTwo);

        $rows = $this->getJson('/api/v1/reports/inventory/stock-summary', $this->headers())
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $rows);

        $byWarehouse = collect($rows)->keyBy('warehouse_id');

        $this->assertSame('10.000000', $byWarehouse[$this->warehouse->id]['on_hand']);
        $this->assertSame('10.000000', $byWarehouse[$this->warehouse->id]['available']);
        $this->assertSame('5.000000', $byWarehouse[$this->warehouseTwo->id]['on_hand']);
        $this->assertSame($this->product->sku, $rows[0]['product_sku']);
        $this->assertSame($this->unit->code, $rows[0]['unit_code']);
    }

    public function test_stock_summary_subtracts_reserved_from_available(): void
    {
        $this->seedStock('20', '1000');

        $this->inventory()->reserve(
            ['product_id' => $this->product->id, 'product_variant_id' => null, 'unit_id' => $this->unit->id, 'quantity' => '7'],
            ['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'warehouse_id' => $this->warehouse->id, 'location_id' => null],
            StockAdjustment::factory()->for($this->company)->for($this->warehouse)->create(),
            '7'
        );

        $row = $this->getJson('/api/v1/reports/inventory/stock-summary', $this->headers())
            ->assertOk()
            ->json('data.0');

        $this->assertSame('20.000000', $row['on_hand']);
        $this->assertSame('7.000000', $row['reserved']);
        $this->assertSame('13.000000', $row['available']);
    }

    public function test_stock_summary_filters_by_warehouse_and_search(): void
    {
        $this->seedStock('10', '1000');
        $this->seedStock('5', '1000', $this->warehouseTwo);

        $rows = $this->getJson('/api/v1/reports/inventory/stock-summary?warehouse_id='.$this->warehouseTwo->id, $this->headers())
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($this->warehouseTwo->id, $rows[0]['warehouse_id']);

        $rows = $this->getJson('/api/v1/reports/inventory/stock-summary?search=AAA', $this->headers())
            ->assertOk()
            ->json('data');

        // The product was stocked in two warehouses, so the search narrows to
        // its two rows and drops the other product entirely.
        $this->assertCount(2, $rows);
        $this->assertSame(['AAA', 'AAA'], collect($rows)->pluck('product_sku')->all());

        $this->assertSame([], $this->getJson('/api/v1/reports/inventory/stock-summary?search=ZZZ', $this->headers())->json('data'));
    }

    public function test_stock_summary_reports_zeros_when_no_stock_exists(): void
    {
        $this->getJson('/api/v1/reports/inventory/stock-summary', $this->headers())
            ->assertOk()
            ->json('data');

        $this->assertSame([], $this->getJson('/api/v1/reports/inventory/stock-summary', $this->headers())->json('data'));
    }

    //
    // Stock card
    //

    public function test_stock_card_returns_chronological_ledger_rows(): void
    {
        $this->seedStock('100', '1000');
        $this->seedOutgoing('30');

        $rows = $this->getJson('/api/v1/reports/inventory/stock-card?product_id='.$this->product->id, $this->headers())
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $rows);

        // The opening lands before the issue, and each row carries its own
        // recorded running balance.
        $this->assertSame('100.000000', $rows[0]['in']);
        $this->assertSame('0.000000', $rows[0]['out']);
        $this->assertSame('100.000000', $rows[0]['balance']);

        $this->assertSame('0.000000', $rows[1]['in']);
        $this->assertSame('30.000000', $rows[1]['out']);
        $this->assertSame('70.000000', $rows[1]['balance']);
    }

    public function test_stock_card_reports_the_recorded_balance_and_does_not_recompute(): void
    {
        $this->seedStock('50', '800');

        // A second opening on the other warehouse keeps its own balance, so the
        // card for the product shows each movement's real balance_after.
        $this->seedStock('25', '800', $this->warehouseTwo);

        $rows = $this->getJson('/api/v1/reports/inventory/stock-card?product_id='.$this->product->id, $this->headers())
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $rows);
        $this->assertSame('50.000000', $rows[0]['balance']);
        $this->assertSame('25.000000', $rows[1]['balance']);
    }

    public function test_stock_card_narrows_to_a_warehouse_and_a_date_window(): void
    {
        $this->seedStock('100', '1000');
        $this->seedStock('40', '1000', $this->warehouseTwo);

        $rows = $this->getJson('/api/v1/reports/inventory/stock-card?product_id='.$this->product->id.'&warehouse_id='.$this->warehouseTwo->id, $this->headers())
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('40.000000', $rows[0]['balance']);
        $this->assertSame($this->warehouseTwo->id, $rows[0]['warehouse_id']);

        $rows = $this->getJson('/api/v1/reports/inventory/stock-card?product_id='.$this->product->id.'&date_from='.now()->addDay()->toDateString(), $this->headers())
            ->assertOk()
            ->json('data');

        $this->assertSame([], $rows);
    }

    public function test_stock_card_requires_a_product(): void
    {
        $this->getJson('/api/v1/reports/inventory/stock-card', $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors('product_id');
    }

    //
    // Stock movements
    //

    public function test_stock_movements_filters_by_type_warehouse_and_reference(): void
    {
        $this->seedStock('100', '1000');
        $this->seedOutgoing('20');
        $this->seedStock('10', '1000', $this->warehouseTwo);

        $rows = $this->getJson('/api/v1/reports/inventory/stock-movements?movement_type='.MovementType::Opening->value, $this->headers())
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $rows);
        $this->assertSame(MovementType::Opening->value, $rows[0]['movement_type']);
        $this->assertSame('AAA', $rows[0]['product_sku']);

        $rows = $this->getJson('/api/v1/reports/inventory/stock-movements?warehouse_id='.$this->warehouseTwo->id, $this->headers())
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($this->warehouseTwo->id, $rows[0]['warehouse_id']);

        // A reference filter narrows to the one document that caused a movement.
        $referenceId = $rows[0]['reference_id'];

        $rows = $this->getJson('/api/v1/reports/inventory/stock-movements?reference_type='.$rows[0]['reference_type'].'&reference_id='.$referenceId, $this->headers())
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
    }

    public function test_stock_movements_is_paginated(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->seedStock('1', '100');
        }

        $response = $this->getJson('/api/v1/reports/inventory/stock-movements?per_page=2', $this->headers())
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(3, $response->json('meta.total'));
        $this->assertSame(2, $response->json('meta.last_page'));
    }

    //
    // Low stock
    //

    public function test_low_stock_flags_products_at_or_below_the_reorder_point(): void
    {
        // AAA has a reorder point of 5 and 2 on hand, so it is low.
        $this->seedStock('2', '100');

        // BBB has a reorder point of 20 and 50 on hand, so it is not.
        $this->inventory()->move(
            ['product_id' => $this->otherProduct->id, 'product_variant_id' => null, 'unit_id' => $this->unit->id, 'quantity' => '50'],
            MovementType::Opening,
            ['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'warehouse_id' => $this->warehouse->id, 'location_id' => null],
            StockAdjustment::factory()->for($this->company)->for($this->warehouse)->create(),
            '100',
            $this->user->id
        );

        $rows = $this->getJson('/api/v1/reports/inventory/low-stock', $this->headers())
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('AAA', $rows[0]['product_sku']);
        $this->assertSame('2.000000', $rows[0]['on_hand']);
        $this->assertSame('2.000000', $rows[0]['available']);
        $this->assertSame('5.000000', $rows[0]['reorder_point']);

        // The product carries a master reorder quantity, which is what is
        // suggested; nothing is invented.
        $this->assertSame('12.000000', $rows[0]['suggested_reorder_quantity']);
    }

    public function test_low_stock_reports_an_unstocked_product_at_zero(): void
    {
        $rows = $this->getJson('/api/v1/reports/inventory/low-stock', $this->headers())
            ->assertOk()
            ->json('data');

        // No balance rows exist yet, so every tracked product reports zero,
        // which is below both reorder points.
        $this->assertNotEmpty($rows);
        $this->assertSame('0.000000', $rows[0]['on_hand']);
        $this->assertSame('0.000000', $rows[0]['available']);
    }

    public function test_low_stock_ignores_products_that_do_not_track_inventory(): void
    {
        Product::factory()->for($this->company)->create([
            'sku' => 'NOTRACK',
            'default_unit_id' => $this->unit->id,
            'track_inventory' => false,
            'reorder_point' => '100',
        ]);

        $rows = $this->getJson('/api/v1/reports/inventory/low-stock', $this->headers())
            ->assertOk()
            ->json('data');

        $this->assertNotContains('NOTRACK', collect($rows)->pluck('product_sku')->all());
    }

    //
    // Stock valuation
    //

    public function test_stock_valuation_matches_hand_computed_bcmath_totals(): void
    {
        $this->seedStock('10', '1000');
        $this->seedStock('5', '400', $this->warehouseTwo);

        $response = $this->getJson('/api/v1/reports/inventory/stock-valuation', $this->headers())
            ->assertOk();

        $rows = $response->json('data.rows');
        $subtotals = $response->json('data.subtotals');
        $total = $response->json('data.total');

        $this->assertCount(2, $rows);

        $byWarehouse = collect($rows)->keyBy('warehouse_id');
        $this->assertSame('10000.0000', $byWarehouse[$this->warehouse->id]['on_hand_value']);
        $this->assertSame('2000.0000', $byWarehouse[$this->warehouseTwo->id]['on_hand_value']);

        $expected = DecimalMath::add(
            DecimalMath::mul('10', '1000'),
            DecimalMath::mul('5', '400')
        );

        $this->assertSame($expected, $total);
        $this->assertSame(DecimalMath::mul('10', '1000'), $subtotals[(string) $this->warehouse->id]);
        $this->assertSame(DecimalMath::mul('5', '400'), $subtotals[(string) $this->warehouseTwo->id]);
    }

    public function test_stock_valuation_reports_zero_when_empty(): void
    {
        $response = $this->getJson('/api/v1/reports/inventory/stock-valuation', $this->headers())
            ->assertOk();

        $this->assertSame([], $response->json('data.rows'));
        $this->assertSame('0', $response->json('data.total'));
        $this->assertSame([], $response->json('data.subtotals'));
    }

    //
    // Stock opnames
    //

    public function test_stock_opnames_summarise_system_versus_counted(): void
    {
        $opname = StockOpname::factory()->for($this->company)->for($this->branch)->for($this->warehouse)->create([
            'status' => 'posted',
            'counted_by' => $this->user->id,
        ]);

        StockOpnameItem::create([
            'stock_opname_id' => $opname->id,
            'product_id' => $this->product->id,
            'unit_id' => $this->unit->id,
            'system_quantity' => '10',
            'counted_quantity' => '8',
            'difference' => '-2',
        ]);

        $row = $this->getJson('/api/v1/reports/inventory/stock-opnames', $this->headers())
            ->assertOk()
            ->json('data.0');

        $this->assertSame($opname->number, $row['number']);
        $this->assertSame(1, $row['items_count']);
        $this->assertSame('10.000000', $row['system_quantity']);
        $this->assertSame('8.000000', $row['counted_quantity']);
        $this->assertSame('-2.000000', $row['variance_quantity']);
    }

    //
    // Warehouse transfers
    //

    public function test_warehouse_transfers_report_status_and_item_counts(): void
    {
        $transfer = WarehouseTransfer::factory()->for($this->company)->create([
            'from_warehouse_id' => $this->warehouse->id,
            'to_warehouse_id' => $this->warehouseTwo->id,
            'status' => 'completed',
            'requested_by' => $this->user->id,
        ]);

        WarehouseTransferItem::create([
            'warehouse_transfer_id' => $transfer->id,
            'product_id' => $this->product->id,
            'unit_id' => $this->unit->id,
            'quantity' => '5',
            'quantity_received' => '5',
        ]);

        $row = $this->getJson('/api/v1/reports/inventory/warehouse-transfers', $this->headers())
            ->assertOk()
            ->json('data.0');

        $this->assertSame($transfer->number, $row['number']);
        $this->assertSame('completed', $row['status']);
        $this->assertSame(1, $row['items_count']);
        $this->assertSame('5.000000', $row['total_quantity']);
        $this->assertSame('5.000000', $row['total_received']);
    }

    //
    // Authorisation
    //

    public function test_a_user_without_the_permission_is_forbidden(): void
    {
        $user = $this->authenticatedUser(['inventory.view']);

        $endpoints = [
            'stock-summary',
            'stock-card?product_id='.$this->product->id,
            'stock-movements',
            'low-stock',
            'stock-valuation',
            'stock-opnames',
            'warehouse-transfers',
        ];

        foreach ($endpoints as $endpoint) {
            $this->getJson('/api/v1/reports/inventory/'.$endpoint, $this->authHeaders($user))
                ->assertStatus(403)
                ->assertJsonPath('success', false);
        }
    }

    //
    // Multi-tenancy
    //

    public function test_a_user_cannot_se_another_companys_stock(): void
    {
        $otherCompany = Company::factory()->create();
        $otherBranch = Branch::factory()->for($otherCompany)->create();
        $otherWarehouse = Warehouse::factory()->for($otherCompany)->for($otherBranch)->create();
        $otherUnit = Unit::factory()->for($otherCompany)->create();

        $otherProduct = Product::factory()->for($otherCompany)->create([
            'sku' => 'SECRET',
            'default_unit_id' => $otherUnit->id,
        ]);

        $this->inventory()->move(
            ['product_id' => $otherProduct->id, 'product_variant_id' => null, 'unit_id' => $otherUnit->id, 'quantity' => '99'],
            MovementType::Opening,
            ['company_id' => $otherCompany->id, 'branch_id' => $otherBranch->id, 'warehouse_id' => $otherWarehouse->id, 'location_id' => null],
            StockAdjustment::factory()->for($otherCompany)->for($otherWarehouse)->create(),
            '500'
        );

        // The other company's stock is invisible, both by scope and by an
        // explicit company_id the user cannot reach.
        $this->assertSame([], $this->getJson('/api/v1/reports/inventory/stock-summary', $this->headers())->json('data'));
        $this->assertSame([], $this->getJson('/api/v1/reports/inventory/stock-summary?company_id='.$otherCompany->id, $this->headers())->json('data'));
        $this->assertSame('0', $this->getJson('/api/v1/reports/inventory/stock-valuation', $this->headers())->json('data.total'));
        $this->assertSame([], $this->getJson('/api/v1/reports/inventory/stock-movements', $this->headers())->json('data'));
    }

    public function test_a_user_with_two_companies_sees_only_the_resolved_one(): void
    {
        $otherCompany = Company::factory()->create();
        $otherBranch = Branch::factory()->for($otherCompany)->create();
        $otherWarehouse = Warehouse::factory()->for($otherCompany)->for($otherBranch)->create();

        $this->user->companies()->attach($otherCompany->id);
        $this->user->warehouses()->attach($otherWarehouse->id);
        $this->user->clearPermissionCache($this->company->id);

        $this->seedStock('10', '1000');
        $this->inventory()->move(
            ['product_id' => $this->otherProduct->id, 'product_variant_id' => null, 'unit_id' => $this->unit->id, 'quantity' => '77'],
            MovementType::Opening,
            ['company_id' => $otherCompany->id, 'branch_id' => $otherBranch->id, 'warehouse_id' => $otherWarehouse->id, 'location_id' => null],
            StockAdjustment::factory()->for($otherCompany)->for($otherWarehouse)->create(),
            '100'
        );

        // Resolved to the header's company: the other company's 77 units stay
        // out of the valuation total.
        $this->assertSame(
            DecimalMath::mul('10', '1000'),
            $this->getJson('/api/v1/reports/inventory/stock-valuation', $this->headers())->json('data.total')
        );
    }
}
