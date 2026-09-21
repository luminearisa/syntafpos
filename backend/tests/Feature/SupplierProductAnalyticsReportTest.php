<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Permission;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReturn;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupplierProductAnalyticsReportTest extends TestCase
{
    protected Unit $unit;

    protected Supplier $supplier;

    protected Supplier $supplierTwo;

    protected Product $product;

    protected Product $productTwo;

    protected Warehouse $warehouseTwo;

    protected function setUp(): void
    {
        parent::setUp();

        // Receiving is the only way to put real stock behind a product, so the
        // purchasing permissions come along with both report permissions.
        $this->user = $this->authenticatedUser([
            'purchases.view', 'purchases.receive', 'inventory.view',
            'reports.purchasing', 'reports.inventory',
        ]);

        $this->unit = Unit::factory()->for($this->company)->create();
        $this->supplier = Supplier::factory()->for($this->company)->create();
        $this->supplierTwo = Supplier::factory()->for($this->company)->create();
        $this->warehouseTwo = Warehouse::factory()->for($this->company)->for($this->branch)->create();
        // Deterministic names keep the analytics page order stable, so a test
        // reading page one always finds the product it seeded there.
        $this->product = $this->makeProduct(['name' => 'Alpha report product']);
        $this->productTwo = $this->makeProduct(['name' => 'Beta report product']);
    }

    protected function makeProduct(array $attributes = []): Product
    {
        return Product::factory()->for($this->company)->create(array_merge([
            'sku' => 'SKU-PROD-'.uniqid(),
            'name' => 'Report product '.uniqid(),
            'default_unit_id' => $this->unit->id,
            'cost_price' => '1000.0000',
            'selling_price' => '1500.0000',
        ], $attributes));
    }

    protected function headers(User $user, array $context = []): array
    {
        return $this->authHeaders($user, $context);
    }

    /**
     * A posted receipt written straight onto the models: enough for the supplier
     * report, which reads the purchasing documents only.
     *
     * @param  array<int, array{product_id: int, quantity: string, unit_cost: string}>  $lines
     * @param  array{warehouse?: ?Warehouse, unit?: ?Unit}  $context
     */
    protected function receive(Supplier $supplier, string $date, array $lines, array $context = []): GoodsReceipt
    {
        $receipt = GoodsReceipt::factory()
            ->for($this->company)
            ->for($context['branch'] ?? $this->branch)
            ->for($context['warehouse'] ?? $this->warehouse)
            ->for($supplier)
            ->create(['receipt_date' => $date]);

        foreach ($lines as $line) {
            GoodsReceiptItem::create([
                'goods_receipt_id' => $receipt->id,
                'product_id' => $line['product_id'],
                'unit_id' => ($context['unit'] ?? $this->unit)->id,
                'quantity_received' => $line['quantity'],
                'unit_price' => $line['unit_cost'],
                'unit_cost' => $line['unit_cost'],
            ]);
        }

        return $receipt;
    }

    /**
     * A receipt posted through the API, so the inventory engine books the
     * balances and ledger rows the product report reads.
     *
     * @param  array<int, array{product_id: int, quantity: string, unit_cost: string}>  $lines
     * @param  array{company?: ?Company, warehouse?: ?Warehouse, unit?: ?Unit}  $context
     */
    protected function postReceipt(Supplier $supplier, string $date, array $lines, array $context = []): GoodsReceipt
    {
        $company = $context['company'] ?? $this->company;
        $warehouse = $context['warehouse'] ?? $this->warehouse;
        $unit = $context['unit'] ?? $this->unit;

        $id = $this->postJson('/api/v1/goods-receipts', [
            'company_id' => $company->id,
            'branch_id' => $warehouse->branch_id ?? $this->branch->id,
            'warehouse_id' => $warehouse->id,
            'supplier_id' => $supplier->id,
            'receipt_date' => $date,
            'items' => collect($lines)->map(fn (array $line) => [
                'product_id' => $line['product_id'],
                'unit_id' => $unit->id,
                'quantity_received' => $line['quantity'],
                'unit_cost' => $line['unit_cost'],
            ])->all(),
        ], $this->headers($this->user, ['company_id' => $company->id]))
            ->assertCreated()
            ->json('data.id');

        $this->postJson("/api/v1/goods-receipts/{$id}/post", [], $this->headers($this->user, ['company_id' => $company->id]))->assertOk();

        return GoodsReceipt::findOrFail($id);
    }

    protected function openOrder(Supplier $supplier, string $status, string $grandTotal, string $date = '2026-09-01'): PurchaseOrder
    {
        return PurchaseOrder::factory()
            ->for($this->company)
            ->for($this->warehouse)
            ->for($supplier)
            ->create([
                'order_date' => $date,
                'status' => $status,
                'grand_total' => $grandTotal,
            ]);
    }

    /**
     * One supplier's row out of the summary page.
     */
    protected function summaryRow(int $supplierId, array $params = []): ?array
    {
        return collect($this->summaryRows($params))
            ->firstWhere('supplier_id', $supplierId);
    }

    protected function summaryRows(array $params = []): array
    {
        return $this->getJson('/api/v1/reports/suppliers/summary'.($params ? '?'.http_build_query($params) : ''), $this->headers($this->user))
            ->assertOk()
            ->json('data');
    }

    protected function analyticsRow(int $productId, array $params = []): ?array
    {
        return collect($this->analyticsRows($params))
            ->firstWhere('product_id', $productId);
    }

    protected function analyticsRows(array $params = []): array
    {
        return $this->getJson('/api/v1/reports/products/analytics'.($params ? '?'.http_build_query($params) : ''), $this->headers($this->user))
            ->assertOk()
            ->json('data');
    }

    /**
     * A second company, attached to the same user, holding data the report for
     * the primary company must never reach.
     */
    protected function otherCompany(): array
    {
        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->for($branch)->create();
        $supplier = Supplier::factory()->for($company)->create();

        $this->user->companies()->attach($company->id);

        // A user crossing companies carries their report permissions into each
        // one, so the isolation test reads a context it is genuinely allowed to
        // see rather than tripping the permission gate.
        $role = Role::create([
            'company_id' => $company->id,
            'name' => 'cross_company_'.uniqid(),
            'display_name' => 'Cross Company',
        ]);

        $role->permissions()->sync(
            Permission::whereIn('name', [
                'reports.purchasing', 'reports.inventory',
                'purchases.view', 'purchases.receive', 'inventory.view',
            ])->pluck('id')->all()
        );

        $this->user->roles()->attach($role->id);
        $this->user->clearPermissionCache($company->id);

        return [$company, $branch, $warehouse, $supplier];
    }

    /**
     * A second user inside the same business tree, holding only the permissions
     * given, so a missing report permission is a real 403.
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

        $role->permissions()->sync(
            Permission::whereIn('name', $permissions)->pluck('id')->all()
        );

        $user->roles()->attach($role->id);
        $user->clearPermissionCache($this->company->id);

        return $user;
    }

    //
    // Supplier reports (spec §34)
    //

    public function test_supplier_summary_aggregates_receipts_open_orders_and_top_products(): void
    {
        $this->receive($this->supplier, '2026-09-05', [
            ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '1000'],
            ['product_id' => $this->productTwo->id, 'quantity' => '2', 'unit_cost' => '2500'],
        ]);
        $this->receive($this->supplier, '2026-09-20', [
            ['product_id' => $this->product->id, 'quantity' => '5', 'unit_cost' => '1000'],
        ]);

        // Outstanding reads the orders still expecting goods; the received one
        // is closed for goods and contributes nothing.
        $this->openOrder($this->supplier, 'sent', '12000.0000');
        $this->openOrder($this->supplier, 'received', '9000.0000');
        $this->openOrder($this->supplierTwo, 'approved', '3000.0000');

        $row = $this->summaryRow($this->supplier->id);

        // (10 x 1000) + (2 x 2500) + (5 x 1000), from the posted receipts only.
        $this->assertSame('20000.0000', $row['total_purchase']);
        $this->assertSame(2, $row['purchase_count']);
        $this->assertSame('2026-09-20', $row['last_purchase_date']);
        $this->assertSame('12000.0000', $row['outstanding']);
        $this->assertSame($this->supplier->supplier_code, $row['supplier_code']);
        $this->assertSame($this->supplier->name, $row['supplier_name']);

        // Quantities accumulate across receipts and order by quantity desc.
        $this->assertCount(2, $row['top_products']);
        $this->assertSame($this->product->id, $row['top_products'][0]['product_id']);
        $this->assertSame('15.000000', $row['top_products'][0]['quantity']);
        $this->assertSame('15000.0000', $row['top_products'][0]['total']);
        $this->assertSame('2.000000', $row['top_products'][1]['quantity']);
        $this->assertSame('5000.0000', $row['top_products'][1]['total']);

        // A supplier with no activity in the range still appears, with zeros.
        $empty = $this->summaryRow($this->supplierTwo->id);
        $this->assertSame('0.0000', $empty['total_purchase']);
        $this->assertSame(0, $empty['purchase_count']);
        $this->assertSame('3000.0000', $empty['outstanding']);
        $this->assertNull($empty['last_purchase_date']);
        $this->assertSame([], $empty['top_products']);
        $this->assertSame([], $empty['purchase_trend']);
    }

    public function test_supplier_summary_ignores_draft_receipts_and_closed_orders(): void
    {
        $draft = $this->receive($this->supplier, '2026-09-05', [
            ['product_id' => $this->product->id, 'quantity' => '99', 'unit_cost' => '1000'],
        ]);
        $draft->update(['status' => 'draft', 'posted_at' => null]);

        // A draft order is a working copy rather than a commitment, and a
        // closed or cancelled order is no longer outstanding.
        $this->openOrder($this->supplier, 'closed', '8000.0000');
        $this->openOrder($this->supplier, 'cancelled', '7000.0000');
        $this->openOrder($this->supplier, 'draft', '6000.0000');
        $this->openOrder($this->supplier, 'partially_received', '5000.0000');

        $row = $this->summaryRow($this->supplier->id);

        $this->assertSame('0.0000', $row['total_purchase']);
        $this->assertSame(0, $row['purchase_count']);
        // A partially received order is still open, so its committed value
        // stands in full until the order closes.
        $this->assertSame('5000.0000', $row['outstanding']);
    }

    public function test_supplier_summary_top_products_are_bounded_and_ordered(): void
    {
        $products = [];
        for ($i = 0; $i < 6; $i++) {
            $products[] = $this->makeProduct();
        }

        // Six products received in descending quantity; only the top five may
        // appear, and the smallest quantity is the one left out.
        foreach ($products as $index => $product) {
            $this->receive($this->supplier, '2026-09-0'.($index + 1), [
                ['product_id' => $product->id, 'quantity' => (string) (60 - $index * 10), 'unit_cost' => '100'],
            ]);
        }

        $row = $this->summaryRow($this->supplier->id);

        $this->assertCount(5, $row['top_products']);
        $this->assertSame('60.000000', $row['top_products'][0]['quantity']);
        $this->assertSame('50.000000', $row['top_products'][1]['quantity']);
        $this->assertSame('20.000000', $row['top_products'][4]['quantity']);
        $this->assertNotContains($products[5]->id, collect($row['top_products'])->pluck('product_id')->all());
    }

    public function test_supplier_summary_purchase_trend_groups_receipts_by_month(): void
    {
        $this->receive($this->supplier, '2026-08-10', [
            ['product_id' => $this->product->id, 'quantity' => '4', 'unit_cost' => '1000'],
        ]);
        $this->receive($this->supplier, '2026-09-05', [
            ['product_id' => $this->product->id, 'quantity' => '6', 'unit_cost' => '1000'],
        ]);
        $this->receive($this->supplier, '2026-09-28', [
            ['product_id' => $this->product->id, 'quantity' => '2', 'unit_cost' => '1000'],
        ]);

        $row = $this->summaryRow($this->supplier->id);

        $this->assertSame(
            [
                ['month' => '2026-08', 'total' => '4000.0000'],
                ['month' => '2026-09', 'total' => '8000.0000'],
            ],
            $row['purchase_trend']
        );
    }

    public function test_supplier_summary_honours_the_date_and_warehouse_filters(): void
    {
        $this->receive($this->supplier, '2026-08-10', [
            ['product_id' => $this->product->id, 'quantity' => '4', 'unit_cost' => '1000'],
        ]);
        $this->receive($this->supplier, '2026-09-05', [
            ['product_id' => $this->product->id, 'quantity' => '6', 'unit_cost' => '1000'],
        ], ['warehouse' => $this->warehouseTwo]);

        $septemberAtTheSecondWarehouse = $this->summaryRow($this->supplier->id, [
            'date_from' => '2026-09-01',
            'warehouse_id' => (string) $this->warehouseTwo->id,
        ]);

        $this->assertSame('6000.0000', $septemberAtTheSecondWarehouse['total_purchase']);
        $this->assertSame(1, $septemberAtTheSecondWarehouse['purchase_count']);
        $this->assertSame('2026-09-05', $septemberAtTheSecondWarehouse['last_purchase_date']);

        // date_to is inclusive of its day, and the branches narrow the same way.
        $throughAugust = $this->summaryRow($this->supplier->id, ['date_to' => '2026-08-31']);
        $this->assertSame('4000.0000', $throughAugust['total_purchase']);
        $this->assertSame([['month' => '2026-08', 'total' => '4000.0000']], $throughAugust['purchase_trend']);

        // Both warehouses sit under the one branch, so the branch filter keeps
        // both receipts while the warehouse filter narrowed to one.
        $byBranch = $this->summaryRow($this->supplier->id, ['branch_id' => (string) $this->branch->id]);
        $this->assertSame('10000.0000', $byBranch['total_purchase']);
        $this->assertSame(2, $byBranch['purchase_count']);
    }

    public function test_supplier_summary_paginates_and_accepts_the_supplier_filter(): void
    {
        $this->receive($this->supplier, '2026-09-05', [
            ['product_id' => $this->product->id, 'quantity' => '3', 'unit_cost' => '1000'],
        ]);

        $response = $this->getJson('/api/v1/reports/suppliers/summary?supplier_id='.$this->supplierTwo->id, $this->headers($this->user))
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->assertSame($this->supplierTwo->id, $response->json('data.0.supplier_id'));
        $this->assertSame('0.0000', $response->json('data.0.total_purchase'));
    }

    public function test_supplier_detail_summarises_and_paginates_the_three_histories(): void
    {
        $this->openOrder($this->supplier, 'sent', '12000.0000', '2026-09-01');
        $this->openOrder($this->supplier, 'sent', '8000.0000', '2026-09-10');

        $this->receive($this->supplier, '2026-09-05', [
            ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '1000'],
        ]);
        $this->receive($this->supplier, '2026-09-20', [
            ['product_id' => $this->product->id, 'quantity' => '5', 'unit_cost' => '1200'],
        ]);

        PurchaseReturn::factory()
            ->for($this->company)
            ->for($this->warehouse)
            ->for($this->supplier)
            ->create([
                'return_date' => '2026-09-25',
                'total_amount' => '3000.0000',
                'reason' => 'damaged',
            ]);

        // One item per page, so the pagination of each section is visible.
        $response = $this->getJson('/api/v1/reports/suppliers/'.$this->supplier->id.'/detail?per_page=1', $this->headers($this->user))
            ->assertOk();

        // The summary block carries the same figures as the list report.
        $this->assertSame('16000.0000', $response->json('data.summary.total_purchase'));
        $this->assertSame('20000.0000', $response->json('data.summary.outstanding'));
        $this->assertSame('2026-09-20', $response->json('data.summary.last_purchase_date'));

        // Each section is paginated on its own, latest first.
        $this->assertCount(1, $response->json('data.purchase_orders'));
        $this->assertSame('8000.0000', $response->json('data.purchase_orders.0.grand_total'));
        $this->assertSame(2, $response->json('meta.purchase_orders.total'));

        $this->assertCount(1, $response->json('data.goods_receipts'));
        // A receipt has no total column: its value is derived from its lines.
        $this->assertSame('6000.0000', $response->json('data.goods_receipts.0.received_total'));
        $this->assertSame('2026-09-20', $response->json('data.goods_receipts.0.receipt_date'));
        $this->assertSame(2, $response->json('meta.goods_receipts.total'));

        $this->assertCount(1, $response->json('data.purchase_returns'));
        $this->assertSame('3000.0000', $response->json('data.purchase_returns.0.total_amount'));
        $this->assertSame(1, $response->json('meta.purchase_returns.total'));

        // The second page advances every section together.
        $this->getJson('/api/v1/reports/suppliers/'.$this->supplier->id.'/detail?per_page=1&page=2', $this->headers($this->user))
            ->assertOk()
            ->assertJsonPath('data.purchase_orders.0.grand_total', '12000.0000')
            ->assertJsonPath('data.goods_receipts.0.received_total', '10000.0000')
            ->assertJsonPath('data.goods_receipts.0.receipt_date', '2026-09-05');
    }

    public function test_supplier_detail_is_a_404_for_another_companys_supplier(): void
    {
        [$company] = $this->otherCompany();
        $foreign = Supplier::factory()->for($company)->create();

        $this->getJson('/api/v1/reports/suppliers/'.$foreign->id.'/detail', $this->headers($this->user))
            ->assertStatus(404);
    }

    public function test_supplier_reports_do_not_leak_another_companys_data(): void
    {
        [$company, $branch, $warehouse, $foreignSupplier] = $this->otherCompany();

        $foreignUnit = Unit::factory()->for($company)->create();
        $foreignProduct = Product::factory()->for($company)->create(['default_unit_id' => $foreignUnit->id]);

        $this->postReceipt($foreignSupplier, '2026-09-05', [
            ['product_id' => $foreignProduct->id, 'quantity' => '7', 'unit_cost' => '9000'],
        ], ['company' => $company, 'warehouse' => $warehouse, 'unit' => $foreignUnit]);

        // Asked about the primary company, the report names its own suppliers
        // only and carries no figure from the other company.
        $rows = $this->summaryRows([]);

        $this->assertNotContains($foreignSupplier->id, collect($rows)->pluck('supplier_id')->all());
        $this->assertSame('0.0000', collect($rows)->firstWhere('supplier_id', $this->supplier->id)['total_purchase']);

        // The other company is visible to the same user, but its figures stay
        // inside its own company context.
        $foreign = $this->getJson('/api/v1/reports/suppliers/summary', $this->headers($this->user, ['company_id' => $company->id]))
            ->assertOk()
            ->json('data');

        $this->assertSame('63000.0000', collect($foreign)->firstWhere('supplier_id', $foreignSupplier->id)['total_purchase']);
    }

    public function test_supplier_summary_keeps_a_constant_query_cost_per_page(): void
    {
        // Three suppliers, each with a receipt, so a page can hold more than one.
        $suppliers = [
            $this->supplier,
            $this->supplierTwo,
            Supplier::factory()->for($this->company)->create(),
        ];

        foreach ($suppliers as $index => $supplier) {
            $this->receive($supplier, '2026-09-0'.($index + 1), [
                ['product_id' => $this->product->id, 'quantity' => '2', 'unit_cost' => '1000'],
            ]);
        }

        $smallPage = $this->countReportQueries('/api/v1/reports/suppliers/summary?per_page=1');
        $fullPage = $this->countReportQueries('/api/v1/reports/suppliers/summary?per_page=3');

        // The aggregates run once per page (spec §43), never once per supplier.
        $this->assertGreaterThan(0, $smallPage);
        $this->assertSame($smallPage, $fullPage);
    }

    public function test_supplier_reports_require_the_purchasing_report_permission(): void
    {
        // The inventory report permission does not unlock purchasing reports.
        $restricted = $this->restrictedUser(['reports.inventory']);

        $this->getJson('/api/v1/reports/suppliers/summary', $this->headers($restricted))
            ->assertStatus(403);

        $this->getJson('/api/v1/reports/suppliers/'.$this->supplier->id.'/detail', $this->headers($restricted))
            ->assertStatus(403);
    }

    //
    // Product analytics (spec §35)
    //

    public function test_product_analytics_reports_stock_cost_price_and_the_exact_margin(): void
    {
        // The catalog cost differs from the received cost on purpose: the
        // balance is the authority once stock exists.
        $product = $this->makeProduct(['cost_price' => '800.0000', 'selling_price' => '1500.0000']);

        $this->postReceipt($this->supplier, '2026-09-05', [
            ['product_id' => $product->id, 'quantity' => '10', 'unit_cost' => '1000'],
        ]);

        $row = $this->analyticsRow($product->id);

        $this->assertSame('10.000000', $row['total_stock']);
        $this->assertSame('1000.0000', $row['current_cost']);
        $this->assertSame('1500.0000', $row['current_price']);
        // Money math is bcmath end to end, so the margin is exact.
        $this->assertSame('500.0000', $row['estimated_margin']);
        $this->assertSame('33.33', $row['estimated_margin_percent']);
        $this->assertSame('2026-09-05', $row['last_purchase_date']);
        $this->assertSame('10.000000', $row['last_purchase_quantity']);
        $this->assertSame($this->supplier->id, $row['last_supplier_id']);
        $this->assertSame($this->supplier->name, $row['last_supplier_name']);
        $this->assertSame(1, $row['movement_count']);
        $this->assertSame(0, $row['days_since_last_movement']);
        $this->assertTrue($row['is_active']);
    }

    public function test_product_analytics_falls_back_to_the_catalog_cost_without_stock(): void
    {
        $product = $this->makeProduct(['cost_price' => '800.0000', 'selling_price' => '1500.0000']);

        // No receipt, no balance: every figure comes from the catalog instead.
        $row = $this->analyticsRow($product->id);

        $this->assertSame('0.000000', $row['total_stock']);
        $this->assertSame('800.0000', $row['current_cost']);
        $this->assertSame('1500.0000', $row['current_price']);
        $this->assertSame('700.0000', $row['estimated_margin']);
        $this->assertSame('46.67', $row['estimated_margin_percent']);
        $this->assertNull($row['last_purchase_date']);
        $this->assertNull($row['last_purchase_quantity']);
        $this->assertNull($row['last_supplier_id']);
        $this->assertNull($row['last_supplier_name']);
        $this->assertSame(0, $row['movement_count']);
        $this->assertNull($row['days_since_last_movement']);
        $this->assertNull($row['last_movement_at']);
    }

    public function test_product_analytics_resolves_the_last_purchase_from_the_latest_receipt(): void
    {
        $this->postReceipt($this->supplier, '2026-09-01', [
            ['product_id' => $this->product->id, 'quantity' => '4', 'unit_cost' => '1000'],
        ]);
        $this->postReceipt($this->supplierTwo, '2026-09-20', [
            ['product_id' => $this->product->id, 'quantity' => '6', 'unit_cost' => '1200'],
        ]);

        $row = $this->analyticsRow($this->product->id);

        $this->assertSame('10.000000', $row['total_stock']);
        // Weighted average over both receipts: (4 x 1000 + 6 x 1200) / 10.
        $this->assertSame('1120.0000', $row['current_cost']);
        $this->assertSame('2026-09-20', $row['last_purchase_date']);
        $this->assertSame('6.000000', $row['last_purchase_quantity']);
        $this->assertSame($this->supplierTwo->id, $row['last_supplier_id']);
        $this->assertSame($this->supplierTwo->name, $row['last_supplier_name']);
        $this->assertSame(2, $row['movement_count']);
        $this->assertSame(0, $row['days_since_last_movement']);
    }

    public function test_product_analytics_ignores_draft_receipts_and_sums_across_warehouses(): void
    {
        $this->postReceipt($this->supplier, '2026-09-05', [
            ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '1000'],
        ], ['warehouse' => $this->warehouseTwo]);

        $draft = GoodsReceipt::factory()
            ->for($this->company)
            ->for($this->branch)
            ->for($this->warehouse)
            ->for($this->supplier)
            ->create(['receipt_date' => '2026-09-10', 'status' => 'draft', 'posted_at' => null]);

        GoodsReceiptItem::create([
            'goods_receipt_id' => $draft->id,
            'product_id' => $this->product->id,
            'unit_id' => $this->unit->id,
            'quantity_received' => '500',
            'unit_cost' => '1',
        ]);

        $row = $this->analyticsRow($this->product->id);

        // The draft booked no stock and no movement, so it cannot move a single
        // figure in the report.
        $this->assertSame('10.000000', $row['total_stock']);
        $this->assertSame('1000.0000', $row['current_cost']);
        $this->assertSame('2026-09-05', $row['last_purchase_date']);
        $this->assertSame('10.000000', $row['last_purchase_quantity']);
        $this->assertSame(1, $row['movement_count']);

        $this->assertSame(1, StockMovement::query()->where('product_id', $this->product->id)->count());
    }

    public function test_product_analytics_paginates_and_is_isolated_by_company(): void
    {
        [$company, $branch, $warehouse, $foreignSupplier] = $this->otherCompany();

        $foreignUnit = Unit::factory()->for($company)->create();
        $foreignProduct = Product::factory()->for($company)->create(['default_unit_id' => $foreignUnit->id]);

        // Stock booked into the other company never reaches this company's report.
        $this->postReceipt($foreignSupplier, '2026-09-05', [
            ['product_id' => $foreignProduct->id, 'quantity' => '99', 'unit_cost' => '5000'],
        ], ['company' => $company, 'warehouse' => $warehouse, 'unit' => $foreignUnit]);

        $this->postReceipt($this->supplier, '2026-09-05', [
            ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '1000'],
        ]);

        $response = $this->getJson('/api/v1/reports/products/analytics?per_page=1', $this->headers($this->user))
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $rows = $response->json('data');
        $ids = collect($rows)->pluck('product_id')->all();

        $this->assertNotContains($foreignProduct->id, $ids);
        $this->assertSame('10.000000', collect($rows)->firstWhere('product_id', $this->product->id)['total_stock']);
        $this->assertSame(1, $response->json('meta.current_page'));
        $this->assertSame(2, $response->json('meta.last_page'));

        // The other company's stock is reported only inside its own context.
        $foreign = $this->analyticsRowsFor($company, ['per_page' => '50']);

        $this->assertSame('99.000000', collect($foreign)->firstWhere('product_id', $foreignProduct->id)['total_stock']);
    }

    public function test_product_analytics_keeps_a_constant_query_cost_per_page(): void
    {
        // Five products with stock and five without, so a page can hold several.
        for ($i = 0; $i < 5; $i++) {
            $product = $this->makeProduct();

            $this->postReceipt($this->supplier, '2026-09-05', [
                ['product_id' => $product->id, 'quantity' => '2', 'unit_cost' => '1000'],
            ]);
        }

        for ($i = 0; $i < 5; $i++) {
            $this->makeProduct();
        }

        $smallPage = $this->countReportQueries('/api/v1/reports/products/analytics?per_page=2');
        $fullPage = $this->countReportQueries('/api/v1/reports/products/analytics?per_page=10');

        // The aggregates run once per page (spec §43), never once per product.
        $this->assertGreaterThan(0, $smallPage);
        $this->assertSame($smallPage, $fullPage);
    }

    public function test_product_analytics_requires_the_inventory_report_permission(): void
    {
        // The purchasing report permission does not unlock stock-reading reports.
        $restricted = $this->restrictedUser(['reports.purchasing']);

        $this->getJson('/api/v1/reports/products/analytics', $this->headers($restricted))
            ->assertStatus(403);
    }

    /**
     * The queries a report endpoint issued against its own tables, so the cost
     * of a page can be shown to be constant rather than linear in its size.
     */
    protected function countReportQueries(string $url): int
    {
        // The log persists between calls, so it is flushed on both sides of the
        // request or one measurement would include the previous one.
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson($url, $this->headers($this->user))->assertOk();

        $log = DB::getQueryLog();

        DB::disableQueryLog();
        DB::flushQueryLog();

        return collect($log)
            ->filter(fn (array $entry) => str_contains($entry['query'], 'goods_receipt')
                || str_contains($entry['query'], 'purchase_order')
                || str_contains($entry['query'], 'stock_balance')
                || str_contains($entry['query'], 'stock_movement'))
            ->count();
    }

    /**
     * Analytics scoped explicitly at another company.
     */
    protected function analyticsRowsFor(Company $company, array $params = []): array
    {
        return $this->getJson('/api/v1/reports/products/analytics?'.http_build_query($params), $this->headers($this->user, ['company_id' => $company->id]))
            ->assertOk()
            ->json('data');
    }
}
