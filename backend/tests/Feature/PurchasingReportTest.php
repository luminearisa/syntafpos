<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\GoodsReceipt;
use App\Models\Permission;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReturn;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Tax;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PurchaseCalculationService;
use Tests\TestCase;

class PurchasingReportTest extends TestCase
{
    protected User $user;

    protected Unit $unit;

    protected Supplier $supplierA;

    protected Supplier $supplierB;

    protected Tax $tax;

    protected Product $productA;

    protected Product $productB;

    protected PurchaseCalculationService $calc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->authenticatedUser(['reports.purchasing']);

        $this->calc = app(PurchaseCalculationService::class);

        $this->unit = Unit::factory()->for($this->company)->create();
        $this->tax = Tax::factory()->for($this->company)->create([
            'rate' => '11.0000',
            'type' => 'exclusive',
        ]);
        $this->supplierA = Supplier::factory()->for($this->company)->create(['name' => 'Alpha Supply']);
        $this->supplierB = Supplier::factory()->for($this->company)->create(['name' => 'Beta Supply']);

        $this->productA = $this->makeProduct('PA-001', 'Product Alpha');
        $this->productB = $this->makeProduct('PB-002', 'Product Beta');
    }

    protected function makeProduct(string $sku, string $name): Product
    {
        return Product::factory()->for($this->company)->create([
            'sku' => $sku,
            'name' => $name,
            'default_unit_id' => $this->unit->id,
            'tax_id' => $this->tax->id,
            'is_purchasable' => true,
            'cost_price' => '1000.0000',
        ]);
    }

    protected function headers(array $context = []): array
    {
        return $this->authHeaders($this->user, $context);
    }

    /**
     * Build an order whose stored money columns are the derived ones, so every
     * assertion reads exactly what the decimal engine would compute.
     *
     * @param  list<array{product_id: int, quantity: string, unit_price: string, tax_rate?: string, discount?: string}>  $lines
     */
    protected function createOrder(array $lines, array $overrides = []): PurchaseOrder
    {
        // The supplier is a relation, so it is consumed here rather than
        // forwarded into create() as a column value.
        $supplier = $overrides['supplier'] ?? $this->supplierA;
        unset($overrides['supplier']);

        $order = PurchaseOrder::factory()
            ->for($this->company)
            ->for($this->branch)
            ->for($this->warehouse)
            ->for($supplier)
            ->create(array_merge([
                'order_date' => now()->toDateString(),
                'expected_date' => now()->addDays(7)->toDateString(),
                'status' => 'approved',
                'shipping_cost' => '0',
                'other_charges' => '0',
                'discount_total' => '0',
            ], $overrides));

        foreach ($lines as $line) {
            $quantity = $line['quantity'];
            $unitPrice = $line['unit_price'];
            $taxRate = $line['tax_rate'] ?? '0';
            $discount = $line['discount'] ?? '0';

            $gross = bcmul($quantity, $unitPrice, 4);
            $netPrice = bcsub($gross, $discount, 4);
            $taxAmount = bcdiv(bcmul($netPrice, $taxRate, 4), '100', 4);

            $order->items()->create([
                'product_id' => $line['product_id'],
                'unit_id' => $this->unit->id,
                'quantity' => $quantity,
                'quantity_received' => $line['quantity_received'] ?? '0',
                'unit_price' => $unitPrice,
                'discount' => $discount,
                'discount_type' => 'amount',
                'tax_rate' => $taxRate,
                'net_price' => $netPrice,
                'tax_amount' => $taxAmount,
                'subtotal' => bcadd($netPrice, $taxAmount, 4),
            ]);
        }

        // The report reads the stored header columns, so they have to be the
        // recomputed ones rather than whatever the factory happened to leave.
        $this->calc->applyTotals($order);

        return $order->fresh();
    }

    protected function createReceipt(Supplier $supplier, array $items, array $overrides = []): GoodsReceipt
    {
        $receipt = GoodsReceipt::factory()
            ->for($this->company)
            ->for($this->branch)
            ->for($this->warehouse)
            ->for($supplier)
            ->create(array_merge([
                'receipt_date' => now()->toDateString(),
                'status' => 'posted',
                'posted_at' => now(),
            ], $overrides));

        $receipt->items()->createMany($items);

        return $receipt;
    }

    protected function createReturn(Supplier $supplier, string $total, array $items = [], array $overrides = []): PurchaseReturn
    {
        $return = PurchaseReturn::factory()
            ->for($this->company)
            ->for($this->branch)
            ->for($this->warehouse)
            ->for($supplier)
            ->create(array_merge([
                'return_date' => now()->toDateString(),
                'status' => 'posted',
                'posted_at' => now(),
                'total_amount' => $total,
            ], $overrides));

        $return->items()->createMany($items);

        return $return;
    }

    protected function summary(array $params = []): array
    {
        return $this->getJson('/api/v1/reports/purchasing/summary?'.http_build_query($params), $this->headers())
            ->assertOk()
            ->json('data');
    }

    /**
     * A user holding every permission but reports.purchasing, so the gate is
     * what stops them rather than authentication.
     */
    protected function forbiddenUser(): User
    {
        return $this->restrictedUser(['purchases.view', 'reports.inventory']);
    }

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

    public function test_summary_totals_match_the_computed_decimal_values(): void
    {
        $this->createOrder([
            ['product_id' => $this->productA->id, 'quantity' => '10', 'unit_price' => '1000'],
            ['product_id' => $this->productB->id, 'quantity' => '5', 'unit_price' => '2000'],
        ]);

        $this->createOrder([
            ['product_id' => $this->productA->id, 'quantity' => '4', 'unit_price' => '1000', 'tax_rate' => '11'],
        ], ['supplier' => $this->supplierB]);

        $data = $this->summary();

        $this->assertSame(2, $data['totals']['total_orders']);
        $this->assertMoneyEquals('24000.0000', $data['totals']['total_gross']);
        $this->assertMoneyEquals('0.0000', $data['totals']['total_discount']);
        // 4000 net at 11% exclusive tax.
        $this->assertMoneyEquals('440.0000', $data['totals']['total_tax']);
        $this->assertMoneyEquals('24440.0000', $data['totals']['total_grand_total']);
        $this->assertSame('19.000000', $data['totals']['total_quantity']);
        $this->assertSame([], $data['groups']);
    }

    public function test_summary_grouped_by_supplier_sums_each_companys_orders(): void
    {
        $this->createOrder([['product_id' => $this->productA->id, 'quantity' => '10', 'unit_price' => '1000']], ['supplier' => $this->supplierA]);
        $this->createOrder([['product_id' => $this->productB->id, 'quantity' => '2', 'unit_price' => '500', 'tax_rate' => '11']], ['supplier' => $this->supplierB]);

        $groups = collect($this->summary(['group_by' => 'supplier'])['groups'])->keyBy('label');

        $this->assertCount(2, $groups);

        $alpha = $groups['Alpha Supply'];
        $this->assertSame(1, $alpha['order_count']);
        $this->assertSame('10.000000', $alpha['total_quantity']);
        $this->assertMoneyEquals('10000.0000', $alpha['total_gross']);
        $this->assertMoneyEquals('10000.0000', $alpha['total_grand_total']);

        $beta = $groups['Beta Supply'];
        $this->assertSame('2.000000', $beta['total_quantity']);
        $this->assertMoneyEquals('1000.0000', $beta['total_gross']);
        $this->assertMoneyEquals('110.0000', $beta['total_tax']);
        $this->assertMoneyEquals('1110.0000', $beta['total_grand_total']);
    }

    public function test_summary_grouped_by_status_separates_the_workflow_steps(): void
    {
        $this->createOrder([['product_id' => $this->productA->id, 'quantity' => '1', 'unit_price' => '100']], ['status' => 'approved']);
        $this->createOrder([['product_id' => $this->productA->id, 'quantity' => '2', 'unit_price' => '100']], ['status' => 'sent']);

        $groups = collect($this->summary(['group_by' => 'status'])['groups'])->keyBy('key');

        $this->assertSame(1, $groups['approved']['order_count']);
        $this->assertSame(1, $groups['sent']['order_count']);
        $this->assertSame('2.000000', $groups['sent']['total_quantity']);
    }

    public function test_summary_grouped_by_month_buckets_by_order_date(): void
    {
        $this->createOrder([['product_id' => $this->productA->id, 'quantity' => '1', 'unit_price' => '100']], ['order_date' => now()->startOfMonth()->toDateString()]);
        $this->createOrder([['product_id' => $this->productA->id, 'quantity' => '1', 'unit_price' => '100']], ['order_date' => now()->subMonth()->startOfMonth()->toDateString()]);

        $data = $this->summary(['group_by' => 'month']);

        $keys = collect($data['groups'])->pluck('key')->all();

        $this->assertCount(2, $keys);
        $this->assertContains(now()->format('Y-m'), $keys);
        $this->assertContains(now()->subMonth()->format('Y-m'), $keys);
        $this->assertSame(collect($data['groups'])->firstWhere('key', now()->format('Y-m'))['label'], now()->format('Y-m'));
    }

    public function test_summary_grouped_by_product_uses_line_derived_totals(): void
    {
        $this->createOrder([
            ['product_id' => $this->productA->id, 'quantity' => '10', 'unit_price' => '1000'],
            ['product_id' => $this->productB->id, 'quantity' => '2', 'unit_price' => '500'],
        ]);

        $groups = collect($this->summary(['group_by' => 'product'])['groups'])->keyBy('label');

        $alpha = $groups['Product Alpha'];
        $this->assertSame('10.000000', $alpha['total_quantity']);
        $this->assertMoneyEquals('10000.0000', $alpha['total_gross']);
        // Header shipping and header discount never belong to a product, so a
        // product group carries only its own line values.
        $this->assertMoneyEquals('10000.0000', $alpha['total_grand_total']);
        $this->assertMoneyEquals('1000.0000', $groups['Product Beta']['total_gross']);
    }

    public function test_summary_grouped_by_warehouse_and_branch(): void
    {
        $this->createOrder([['product_id' => $this->productA->id, 'quantity' => '3', 'unit_price' => '100']]);

        $warehouse = collect($this->summary(['group_by' => 'warehouse'])['groups'])->firstWhere('key', (string) $this->warehouse->id);
        $this->assertNotNull($warehouse);
        $this->assertSame($this->warehouse->name, $warehouse['label']);
        $this->assertSame(1, $warehouse['order_count']);

        $branch = collect($this->summary(['group_by' => 'branch'])['groups'])->firstWhere('key', (string) $this->branch->id);
        $this->assertNotNull($branch);
        $this->assertSame($this->branch->name, $branch['label']);
    }

    public function test_summary_respects_the_date_range_and_warehouse_filters(): void
    {
        $this->createOrder([['product_id' => $this->productA->id, 'quantity' => '1', 'unit_price' => '100']], ['order_date' => now()->toDateString()]);
        $this->createOrder([['product_id' => $this->productA->id, 'quantity' => '1', 'unit_price' => '100']], ['order_date' => now()->subDays(40)->toDateString()]);

        $inWindow = $this->summary(['start_date' => now()->subDays(10)->toDateString(), 'end_date' => now()->toDateString()]);
        $this->assertSame(1, $inWindow['totals']['total_orders']);

        $empty = $this->summary(['start_date' => now()->subDays(100)->toDateString(), 'end_date' => now()->subDays(50)->toDateString()]);
        $this->assertSame(0, $empty['totals']['total_orders']);
        $this->assertMoneyEquals('0.0000', $empty['totals']['total_grand_total']);
        $this->assertSame('0.000000', $empty['totals']['total_quantity']);
    }

    public function test_an_empty_filter_window_reports_zeros_and_no_rows(): void
    {
        $data = $this->summary();

        $this->assertSame(0, $data['totals']['total_orders']);
        $this->assertMoneyEquals('0.0000', $data['totals']['total_grand_total']);
        $this->assertSame([], $data['groups']);
    }

    public function test_detail_report_lists_line_rows_with_their_orders(): void
    {
        $order = $this->createOrder([
            ['product_id' => $this->productA->id, 'quantity' => '10', 'unit_price' => '1000'],
            ['product_id' => $this->productB->id, 'quantity' => '2', 'unit_price' => '500'],
        ]);

        $response = $this->getJson('/api/v1/reports/purchasing/detail', $this->headers())
            ->assertOk()
            ->json();

        $this->assertSame(2, $response['meta']['total']);
        $lines = collect($response['data'])->keyBy('product_id');

        $alpha = $lines[$this->productA->id];
        $this->assertSame($order->id, $alpha['purchase_order_id']);
        $this->assertSame($order->number, $alpha['purchase_order']['number']);
        $this->assertSame('approved', $alpha['purchase_order']['status']);
        $this->assertSame('10.000000', (string) $alpha['quantity']);
        $this->assertSame('10000.0000', (string) $alpha['net_price']);
        $this->assertSame($this->productA->name, $alpha['product']['name']);
        $this->assertSame('2.000000', (string) $lines[$this->productB->id]['quantity']);

        // The product filter narrows to the lines of one product.
        $filtered = $this->getJson('/api/v1/reports/purchasing/detail?product_id='.$this->productB->id, $this->headers())
            ->assertOk()
            ->json();

        $this->assertSame(1, $filtered['meta']['total']);
        $this->assertSame($this->productB->id, $filtered['data'][0]['product_id']);
    }

    public function test_by_supplier_aggregates_purchased_received_and_returns(): void
    {
        $this->createOrder([['product_id' => $this->productA->id, 'quantity' => '10', 'unit_price' => '1000']], ['supplier' => $this->supplierA]);
        $this->createOrder([['product_id' => $this->productA->id, 'quantity' => '4', 'unit_price' => '250']], ['supplier' => $this->supplierA]);

        // 6000 of the 11000 ordered has actually landed.
        $this->createReceipt($this->supplierA, [
            ['product_id' => $this->productA->id, 'unit_id' => $this->unit->id, 'quantity_ordered' => '6', 'quantity_received' => '6', 'unit_price' => '1000', 'unit_cost' => '1000'],
        ]);

        // And 500 of that went back.
        $this->createReturn($this->supplierA, '500', [
            ['product_id' => $this->productA->id, 'unit_id' => $this->unit->id, 'quantity' => '0.5', 'unit_cost' => '1000', 'total_amount' => '500'],
        ]);

        $rows = collect($this->getJson('/api/v1/reports/purchasing/by-supplier', $this->headers())->assertOk()->json('data'));

        $this->assertCount(1, $rows);
        $row = $rows->firstWhere('supplier_name', 'Alpha Supply');

        $this->assertSame(2, $row['order_count']);
        $this->assertSame(1, $row['return_count']);
        $this->assertMoneyEquals('11000.0000', $row['total_purchased']);
        $this->assertMoneyEquals('6000.0000', $row['total_received']);
        $this->assertMoneyEquals('500.0000', $row['total_return']);
        $this->assertMoneyEquals('10500.0000', $row['net_purchased']);
    }

    public function test_by_supplier_ignores_draft_returns_and_draft_receipts(): void
    {
        $this->createOrder([['product_id' => $this->productA->id, 'quantity' => '10', 'unit_price' => '1000']], ['supplier' => $this->supplierA]);

        $this->createReceipt($this->supplierA, [
            ['product_id' => $this->productA->id, 'unit_id' => $this->unit->id, 'quantity_ordered' => '2', 'quantity_received' => '2', 'unit_price' => '1000', 'unit_cost' => '1000'],
        ], ['status' => 'draft']);

        $this->createReturn($this->supplierA, '500', [], ['status' => 'draft']);

        $row = collect($this->getJson('/api/v1/reports/purchasing/by-supplier', $this->headers())->assertOk()->json('data'))->first();

        $this->assertMoneyEquals('10000.0000', $row['total_purchased']);
        $this->assertMoneyEquals('0.0000', $row['total_received']);
        $this->assertMoneyEquals('0.0000', $row['total_return']);
        $this->assertMoneyEquals('10000.0000', $row['net_purchased']);
    }

    public function test_by_product_aggregates_quantities_and_net_cost(): void
    {
        $this->createOrder([
            ['product_id' => $this->productA->id, 'quantity' => '10', 'unit_price' => '1000'],
            ['product_id' => $this->productB->id, 'quantity' => '4', 'unit_price' => '250'],
        ]);

        $order = $this->createOrder([['product_id' => $this->productA->id, 'quantity' => '2', 'unit_price' => '1000']]);
        $order->items()->where('product_id', $this->productA->id)->update(['quantity_received' => '8']);
        $order->save();

        $rows = collect($this->getJson('/api/v1/reports/purchasing/by-product', $this->headers())->assertOk()->json('data'))->keyBy('product_sku');

        $alpha = $rows['PA-001'];
        $this->assertSame('12.000000', $alpha['quantity_ordered']);
        $this->assertSame('8.000000', $alpha['quantity_received']);
        $this->assertSame('4.000000', $alpha['remaining_quantity']);
        $this->assertMoneyEquals('12000.0000', $alpha['gross_value']);
        $this->assertMoneyEquals('12000.0000', $alpha['net_value']);
        $this->assertMoneyEquals('1000.0000', $alpha['average_unit_cost']);

        $this->assertSame('4.000000', $rows['PB-002']['quantity_ordered']);
    }

    public function test_by_branch_and_by_warehouse_sum_their_orders(): void
    {
        $this->createOrder([['product_id' => $this->productA->id, 'quantity' => '10', 'unit_price' => '1000']]);

        $branch = collect($this->getJson('/api/v1/reports/purchasing/by-branch', $this->headers())->assertOk()->json('data'))->firstWhere('key', (string) $this->branch->id);
        $this->assertNotNull($branch);
        $this->assertSame(1, $branch['order_count']);
        $this->assertMoneyEquals('10000.0000', $branch['total_purchased']);

        $warehouse = collect($this->getJson('/api/v1/reports/purchasing/by-warehouse', $this->headers())->assertOk()->json('data'))->firstWhere('key', (string) $this->warehouse->id);
        $this->assertNotNull($warehouse);
        $this->assertSame($this->warehouse->name, $warehouse['label']);
        $this->assertMoneyEquals('10000.0000', $warehouse['total_purchased']);
    }

    public function test_returns_report_sums_posted_returns_and_groups_them(): void
    {
        $this->createReturn($this->supplierA, '1500', [
            ['product_id' => $this->productA->id, 'unit_id' => $this->unit->id, 'quantity' => '1.5', 'unit_cost' => '1000', 'total_amount' => '1500'],
        ]);
        // A draft return contributes nothing.
        $this->createReturn($this->supplierB, '999', [], ['status' => 'draft']);

        $totals = $this->getJson('/api/v1/reports/purchasing/returns', $this->headers())->assertOk()->json('data.totals');

        $this->assertSame(1, $totals['total_returns']);
        $this->assertSame('1.500000', $totals['total_quantity']);
        $this->assertMoneyEquals('1500.0000', $totals['total_amount']);

        $bySupplier = $this->getJson('/api/v1/reports/purchasing/returns?group_by=supplier', $this->headers())->assertOk()->json('data.groups');
        $this->assertCount(1, $bySupplier);
        $this->assertSame('Alpha Supply', $bySupplier[0]['label']);
        $this->assertMoneyEquals('1500.0000', $bySupplier[0]['total_amount']);

        $byProduct = $this->getJson('/api/v1/reports/purchasing/returns?group_by=product', $this->headers())->assertOk()->json('data.groups');
        $this->assertSame('Product Alpha', $byProduct[0]['label']);
        $this->assertSame('1.500000', $byProduct[0]['total_quantity']);

        $byDate = $this->getJson('/api/v1/reports/purchasing/returns?group_by=date', $this->headers())->assertOk()->json('data.groups');
        $this->assertSame(now()->toDateString(), $byDate[0]['key']);
    }

    public function test_outstanding_reports_the_remaining_quantity_and_value(): void
    {
        // 10 ordered, 6 received: 4 short at 1000 each.
        $partial = $this->createOrder([['product_id' => $this->productA->id, 'quantity' => '10', 'unit_price' => '1000', 'quantity_received' => '6']]);
        $partial->forceFill(['status' => 'partially_received'])->save();

        $complete = $this->createOrder([['product_id' => $this->productA->id, 'quantity' => '5', 'unit_price' => '1000', 'quantity_received' => '5']]);
        $complete->forceFill(['status' => 'received'])->save();

        $cancelled = $this->createOrder([['product_id' => $this->productA->id, 'quantity' => '5', 'unit_price' => '1000']]);
        $cancelled->forceFill(['status' => 'cancelled'])->save();

        $rows = $this->getJson('/api/v1/reports/purchasing/outstanding', $this->headers())->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame($partial->id, $row['purchase_order_id']);
        $this->assertSame('partially_received', $row['status']);
        $this->assertSame('10.000000', $row['quantity_ordered']);
        $this->assertSame('6.000000', $row['quantity_received']);
        $this->assertSame('4.000000', $row['remaining_quantity']);
        $this->assertMoneyEquals('10000.0000', $row['ordered_value']);
        $this->assertMoneyEquals('6000.0000', $row['received_value']);
        $this->assertMoneyEquals('4000.0000', $row['remaining_value']);
    }

    public function test_outstanding_reports_an_open_order_with_no_receipts_at_all(): void
    {
        $open = $this->createOrder([
            ['product_id' => $this->productA->id, 'quantity' => '10', 'unit_price' => '1000'],
            ['product_id' => $this->productB->id, 'quantity' => '2', 'unit_price' => '500'],
        ]);

        // One row per line still to buy, so the buyer reads an actionable list.
        $rows = collect($this->getJson('/api/v1/reports/purchasing/outstanding', $this->headers())->assertOk()->json('data'))
            ->keyBy('product_id');

        $this->assertCount(2, $rows);
        $this->assertSame($open->id, $rows[$this->productA->id]['purchase_order_id']);

        $alpha = $rows[$this->productA->id];
        $this->assertSame('10.000000', $alpha['quantity_ordered']);
        $this->assertSame('0.000000', $alpha['quantity_received']);
        $this->assertSame('10.000000', $alpha['remaining_quantity']);
        $this->assertMoneyEquals('10000.0000', $alpha['ordered_value']);
        $this->assertMoneyEquals('10000.0000', $alpha['remaining_value']);

        $beta = $rows[$this->productB->id];
        $this->assertSame('2.000000', $beta['remaining_quantity']);
        $this->assertMoneyEquals('1000.0000', $beta['remaining_value']);
    }

    public function test_user_without_the_permission_is_forbidden_on_every_endpoint(): void
    {
        $headers = ['Authorization' => 'Bearer '.$this->forbiddenUser()->createToken('test')->plainTextToken, 'X-Company-Id' => (string) $this->company->id];

        foreach ([
            'summary', 'detail', 'by-supplier', 'by-product', 'by-branch', 'by-warehouse', 'returns', 'outstanding',
        ] as $endpoint) {
            $this->getJson("/api/v1/reports/purchasing/{$endpoint}", $headers)->assertForbidden();
        }
    }

    public function test_another_companys_purchases_are_never_visible(): void
    {
        $other = Company::factory()->create();
        $otherBranch = Branch::factory()->for($other)->create();
        $otherWarehouse = Warehouse::factory()->for($other)->for($otherBranch)->create();
        $otherSupplier = Supplier::factory()->for($other)->create();
        $otherProduct = Product::factory()->for($other)->create(['default_unit_id' => $this->unit->id]);

        $order = PurchaseOrder::factory()->for($other)->for($otherBranch)->for($otherWarehouse)->for($otherSupplier)->create(['status' => 'approved']);
        $order->items()->create([
            'product_id' => $otherProduct->id,
            'unit_id' => $this->unit->id,
            'quantity' => '99',
            'quantity_received' => '0',
            'unit_price' => '9999',
            'net_price' => '989901',
            'subtotal' => '989901',
        ]);
        $this->calc->applyTotals($order);

        $data = $this->summary();
        $this->assertSame(0, $data['totals']['total_orders']);
        $this->assertMoneyEquals('0.0000', $data['totals']['total_grand_total']);

        $outstanding = $this->getJson('/api/v1/reports/purchasing/outstanding', $this->headers())->assertOk()->json('data');
        $this->assertSame([], $outstanding);

        $suppliers = $this->getJson('/api/v1/reports/purchasing/by-supplier', $this->headers())->assertOk()->json('data');
        $this->assertSame([], $suppliers);
    }

    public function test_an_invalid_group_by_is_rejected(): void
    {
        $this->getJson('/api/v1/reports/purchasing/summary?group_by=nope', $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['group_by']);
    }

    /**
     * Money is compared by value at the column scale rather than as a string,
     * because the SQL engine may return an unscaled 0 for an empty sum.
     */
    private function assertMoneyEquals(string $expected, mixed $actual): void
    {
        $this->assertSame(0, bccomp($expected, (string) $actual, 4), "Expected {$expected} but got {$actual}.");
    }
}
