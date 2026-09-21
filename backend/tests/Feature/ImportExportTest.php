<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\GoodsReceipt;
use App\Models\Permission;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Support\Csv;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ImportExportTest extends TestCase
{
    protected User $user;

    protected Unit $unit;

    protected Category $category;

    protected Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        // The catalogue imports resolve against, and every permission the two
        // flows touch.
        $this->user = $this->authenticatedUser([
            'products.view', 'products.create',
            'categories.view', 'categories.create',
            'brands.view', 'brands.create',
            'customers.view', 'customers.create',
            'suppliers.view', 'suppliers.create',
            'inventory.view', 'inventory.adjust',
            'purchases.view',
            'audit.view',
        ]);

        $this->unit = Unit::factory()->for($this->company)->create(['code' => 'PCS']);
        $this->category = Category::factory()->for($this->company)->create(['code' => 'FOOD']);
        $this->brand = Brand::factory()->for($this->company)->create(['code' => 'ACME']);
        $this->warehouse->forceFill(['code' => 'MAIN'])->save();
    }

    protected function headers(): array
    {
        return $this->authHeaders($this->user);
    }

    /**
     * Build a CSV upload from rows of positional fields.
     */
    protected function csvFile(string $name, array $rows): UploadedFile
    {
        $csv = implode(Csv::EOL, array_map(
            static fn (array $row): string => implode(',', array_map(
                static fn (mixed $field): string => is_string($field) && (str_contains($field, ',') || str_contains($field, '"'))
                    ? '"'.str_replace('"', '""', $field).'"'
                    : (string) $field,
                $row
            )),
            $rows
        )).Csv::EOL;

        return UploadedFile::fake()->createWithContent($name, $csv);
    }

    protected function productsCsv(array $rows): UploadedFile
    {
        return $this->csvFile('products.csv', $rows);
    }

    /**
     * A user inside the same business tree holding only the listed permissions,
     * so the security tests exercise real permission checks.
     */
    protected function restrictedUser(array $permissions): User
    {
        $user = User::factory()->create();
        $user->companies()->attach($this->company->id);
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

    /*
     * Import: preview.
     */

    public function test_preview_reports_per_row_errors_and_writes_nothing(): void
    {
        $response = $this->postJson('/api/v1/imports/preview', [
            'entity' => 'product',
            'file' => $this->productsCsv([
                ['sku', 'name', 'cost_price', 'selling_price', 'category_code', 'brand_code', 'unit_code'],
                ['SKU-001', 'Gadget A', '1000', '2000', 'FOOD', 'ACME', 'PCS'],
                ['SKU-002', '', '500', '1000', 'FOOD', 'ACME', 'PCS'],
            ]),
        ], $this->headers());

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.entity', 'product');
        $response->assertJsonPath('data.summary', ['total' => 2, 'valid' => 1, 'invalid' => 1]);

        // The error map is keyed by the row's line number in the file, and the
        // failing field carries the message needed to correct it.
        $errors = $response->json('data.errors');
        $this->assertArrayHasKey(3, $errors);
        $this->assertArrayHasKey('name', $errors[3]);
        $this->assertStringContainsString('name', $errors[3]['name'][0]);

        $response->assertJsonPath('data.rows.0.valid', true);
        $response->assertJsonPath('data.rows.1.valid', false);

        // A preview is read-only.
        $this->assertSame(0, Product::count());
    }

    public function test_preview_of_a_clean_file_reports_no_errors(): void
    {
        $response = $this->postJson('/api/v1/imports/preview', [
            'entity' => 'product',
            'file' => $this->productsCsv([
                ['sku', 'name'],
                ['SKU-001', 'Gadget A'],
                ['SKU-002', 'Gadget B'],
            ]),
        ], $this->headers());

        $response->assertOk();
        $response->assertJsonPath('data.summary', ['total' => 2, 'valid' => 2, 'invalid' => 0]);
        $this->assertSame([], $response->json('data.errors'));
        $this->assertSame(0, Product::count());
    }

    public function test_preview_rejects_a_file_missing_required_columns(): void
    {
        $response = $this->postJson('/api/v1/imports/preview', [
            'entity' => 'product',
            'file' => $this->csvFile('products.csv', [
                ['sku', 'barcode'],
                ['SKU-001', '4006381333931'],
            ]),
        ], $this->headers());

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('file');
        $this->assertStringContainsString('name', $response->json('errors.file.0'));
    }

    public function test_preview_of_an_empty_file_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/imports/preview', [
            'entity' => 'product',
            'file' => $this->csvFile('products.csv', []),
        ], $this->headers());

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('file');
    }

    public function test_ragged_rows_are_handled_without_crashing(): void
    {
        // A row short of the header and one running past it are both data
        // problems, not a reason for the import to fail with an error page.
        $response = $this->postJson('/api/v1/imports/preview', [
            'entity' => 'product',
            'file' => $this->csvFile('products.csv', [
                ['sku', 'name', 'cost_price'],
                ['SKU-001', 'Gadget A'],
                ['SKU-002', 'Gadget B', '500', 'extra'],
            ]),
        ], $this->headers());

        $response->assertOk();
        $response->assertJsonPath('data.summary', ['total' => 2, 'valid' => 2, 'invalid' => 0]);
        $this->assertSame('500', $response->json('data.rows.1.values.cost_price'));
        $this->assertSame(0, Product::count());
    }

    /*
     * Import: commit.
     */

    public function test_clean_commit_persists_every_row_and_audits_it(): void
    {
        $response = $this->postJson('/api/v1/imports/commit', [
            'entity' => 'product',
            'file' => $this->productsCsv([
                ['sku', 'name', 'cost_price', 'selling_price', 'category_code', 'brand_code', 'unit_code'],
                ['SKU-001', 'Gadget A', '1000', '2000', 'FOOD', 'ACME', 'PCS'],
                ['SKU-002', 'Gadget B', '500', '1000', 'FOOD', 'ACME', 'PCS'],
                ['SKU-003', 'Gadget C', '250', '600'],
            ]),
        ], $this->headers());

        $response->assertStatus(201);
        $response->assertJsonPath('data.imported', 3);

        $this->assertSame([
            'SKU-001' => 'Gadget A',
            'SKU-002' => 'Gadget B',
            'SKU-003' => 'Gadget C',
        ], Product::orderBy('sku')->pluck('name', 'sku')->all());

        // The relations resolved against the company's own catalogue.
        $this->assertSame($this->category->id, Product::where('sku', 'SKU-001')->value('category_id'));
        $this->assertSame($this->brand->id, Product::where('sku', 'SKU-001')->value('brand_id'));
        $this->assertSame($this->unit->id, Product::where('sku', 'SKU-001')->value('default_unit_id'));

        // Money kept its exact digits rather than drifting through a float.
        $this->assertSame('1000.0000', (string) Product::where('sku', 'SKU-001')->value('cost_price'));
        $this->assertSame('2000.0000', (string) Product::where('sku', 'SKU-001')->value('selling_price'));

        $audit = AuditLog::query()->where('action', 'import.commit')->first();
        $this->assertNotNull($audit, 'a successful import must be audited');
        $this->assertSame($this->company->id, $audit->company_id);
        $this->assertSame(3, $audit->new_values['rows']);
    }

    public function test_commit_with_invalid_rows_writes_nothing(): void
    {
        $response = $this->postJson('/api/v1/imports/commit', [
            'entity' => 'product',
            'file' => $this->productsCsv([
                ['sku', 'name', 'cost_price', 'selling_price'],
                ['SKU-001', 'Gadget A', '1000', '2000'],
                ['SKU-002', 'Gadget B', 'not-a-price', '2000'],
            ]),
        ], $this->headers());

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('rows.3.cost_price');

        // §44: a partial import is not an outcome this endpoint can produce.
        $this->assertSame(0, Product::count());
        $this->assertSame(0, AuditLog::query()->where('action', 'import.commit')->count());
    }

    public function test_duplicate_sku_within_the_file_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/imports/commit', [
            'entity' => 'product',
            'file' => $this->productsCsv([
                ['sku', 'name'],
                ['SKU-001', 'Gadget A'],
                ['SKU-001', 'Gadget B'],
            ]),
        ], $this->headers());

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('rows.3.sku');
        $this->assertSame(0, Product::count());
    }

    public function test_duplicate_sku_against_an_existing_product_is_rejected(): void
    {
        Product::factory()->for($this->company)->create(['sku' => 'SKU-001']);

        $response = $this->postJson('/api/v1/imports/commit', [
            'entity' => 'product',
            'file' => $this->productsCsv([
                ['sku', 'name'],
                ['SKU-001', 'Gadget A'],
            ]),
        ], $this->headers());

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('rows.2.sku');
        $this->assertSame(1, Product::count());
    }

    public function test_commit_validates_without_a_preceding_preview(): void
    {
        // A commit with no preview behind it still validates the file on the
        // server, never trusting a client's account of it.
        $this->postJson('/api/v1/imports/commit', [
            'entity' => 'product',
            'file' => $this->productsCsv([
                ['sku', 'name'],
                ['SKU-001', 'Gadget A'],
            ]),
        ], $this->headers())
            ->assertStatus(201);

        $this->assertSame(1, Product::count());
    }

    public function test_customer_import_validates_email_and_issues_missing_codes(): void
    {
        $response = $this->postJson('/api/v1/imports/commit', [
            'entity' => 'customer',
            'file' => $this->csvFile('customers.csv', [
                ['name', 'email'],
                ['Ada Lovelace', 'ada@example.com'],
                ['Grace Hopper', 'not-an-email'],
            ]),
        ], $this->headers());

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('rows.3.email');
        $this->assertSame(0, Customer::count());

        // The second attempt drops the bad address and omits the codes, which
        // the numbering engine supplies.
        $this->postJson('/api/v1/imports/commit', [
            'entity' => 'customer',
            'file' => $this->csvFile('customers.csv', [
                ['name', 'email'],
                ['Ada Lovelace', 'ada@example.com'],
                ['Grace Hopper', 'grace@example.com'],
            ]),
        ], $this->headers())
            ->assertStatus(201);

        $customers = Customer::orderBy('name')->get();

        $this->assertSame(2, $customers->count());
        $customers->each(fn (Customer $customer) => $this->assertNotEmpty($customer->customer_code));
        $this->assertSame('ada@example.com', $customers->firstWhere('name', 'Ada Lovelace')->email);
    }

    public function test_supplier_import_rejects_an_email_already_used(): void
    {
        Supplier::factory()->for($this->company)->create(['email' => 'taken@example.com']);

        $response = $this->postJson('/api/v1/imports/commit', [
            'entity' => 'supplier',
            'file' => $this->csvFile('suppliers.csv', [
                ['name', 'email'],
                ['Northwind Traders', 'taken@example.com'],
            ]),
        ], $this->headers());

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('rows.2.email');
        $this->assertSame(1, Supplier::count());
    }

    public function test_category_and_brand_imports_are_company_scoped(): void
    {
        $this->postJson('/api/v1/imports/commit', [
            'entity' => 'category',
            'file' => $this->csvFile('categories.csv', [
                ['code', 'name'],
                ['DRINK', 'Drinks'],
            ]),
        ], $this->headers())->assertStatus(201);

        $this->postJson('/api/v1/imports/commit', [
            'entity' => 'brand',
            'file' => $this->csvFile('brands.csv', [
                ['code', 'name'],
                ['NORTH', 'Northwind'],
            ]),
        ], $this->headers())->assertStatus(201);

        $this->assertSame(2, Category::count());
        $this->assertSame(2, Brand::count());
        $this->assertSame($this->company->id, Category::where('code', 'DRINK')->value('company_id'));
        $this->assertSame($this->company->id, Brand::where('code', 'NORTH')->value('company_id'));
    }

    /*
     * Import: opening stock.
     */

    public function test_opening_stock_import_posts_a_movement_and_updates_the_balance(): void
    {
        $product = Product::factory()->for($this->company)->create([
            'sku' => 'OPEN-001',
            'default_unit_id' => $this->unit->id,
            'cost_price' => '1000.0000',
        ]);

        $response = $this->postJson('/api/v1/imports/commit', [
            'entity' => 'opening_stock',
            'file' => $this->csvFile('opening_stock.csv', [
                ['product_sku', 'warehouse_code', 'quantity', 'unit_cost'],
                ['OPEN-001', 'MAIN', '10', '1500'],
            ]),
        ], $this->headers());

        $response->assertStatus(201);
        $response->assertJsonPath('data.imported', 1);

        $movement = StockMovement::query()->sole();

        $this->assertSame(MovementType::Opening, $movement->movement_type);
        $this->assertSame('10.000000', (string) $movement->quantity);
        $this->assertSame($product->id, $movement->product_id);
        $this->assertSame($this->warehouse->id, $movement->warehouse_id);
        $this->assertSame($this->unit->id, $movement->unit_id);

        // The cost of the opening quantity became the weighted average.
        $balance = StockBalance::query()->sole();

        $this->assertSame('10.000000', (string) $balance->on_hand);
        $this->assertSame('1500.0000', (string) $balance->average_cost);
        $this->assertSame('1500.0000', (string) $balance->last_cost);
    }

    public function test_opening_stock_rejects_an_unknown_product_and_writes_nothing(): void
    {
        $response = $this->postJson('/api/v1/imports/commit', [
            'entity' => 'opening_stock',
            'file' => $this->csvFile('opening_stock.csv', [
                ['product_sku', 'warehouse_code', 'quantity'],
                ['DOES-NOT-EXIST', 'MAIN', '10'],
            ]),
        ], $this->headers());

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('rows.2.product_sku');

        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, StockBalance::count());
    }

    public function test_opening_stock_rejects_a_duplicate_balance_cell(): void
    {
        Product::factory()->for($this->company)->create([
            'sku' => 'OPEN-002',
            'default_unit_id' => $this->unit->id,
        ]);

        $response = $this->postJson('/api/v1/imports/commit', [
            'entity' => 'opening_stock',
            'file' => $this->csvFile('opening_stock.csv', [
                ['product_sku', 'warehouse_code', 'quantity'],
                ['OPEN-002', 'MAIN', '10'],
                ['OPEN-002', 'MAIN', '5'],
            ]),
        ], $this->headers());

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('rows.3.product_sku');
        $this->assertSame(0, StockMovement::count());
    }

    /*
     * Import: authorisation and file safety.
     */

    public function test_user_without_create_permission_cannot_import(): void
    {
        $reader = $this->restrictedUser(['products.view']);

        $this->postJson('/api/v1/imports/preview', [
            'entity' => 'product',
            'file' => $this->productsCsv([['sku', 'name'], ['SKU-001', 'Gadget A']]),
        ], $this->authHeaders($reader))
            ->assertStatus(403);

        $this->assertSame(0, Product::count());
    }

    public function test_opening_stock_import_requires_the_adjust_permission(): void
    {
        $reader = $this->restrictedUser(['inventory.view']);

        $this->postJson('/api/v1/imports/commit', [
            'entity' => 'opening_stock',
            'file' => $this->csvFile('opening_stock.csv', [
                ['product_sku', 'warehouse_code', 'quantity'],
                ['OPEN-001', 'MAIN', '10'],
            ]),
        ], $this->authHeaders($reader))
            ->assertStatus(403);
    }

    public function test_import_into_a_company_the_user_does_not_belong_to_is_refused(): void
    {
        $other = Company::factory()->create();

        $this->postJson('/api/v1/imports/commit', [
            'entity' => 'product',
            'company_id' => $other->id,
            'file' => $this->productsCsv([['sku', 'name'], ['SKU-001', 'Gadget A']]),
        ], $this->headers())
            ->assertStatus(403);

        $this->assertSame(0, Product::count());
    }

    public function test_unknown_import_entity_is_rejected(): void
    {
        $this->postJson('/api/v1/imports/preview', [
            'entity' => 'warehouse',
            'file' => $this->csvFile('warehouses.csv', [['code', 'name'], ['WH-01', 'Main']]),
        ], $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors('entity');
    }

    public function test_non_csv_upload_is_rejected(): void
    {
        $this->postJson('/api/v1/imports/preview', [
            'entity' => 'product',
            'file' => UploadedFile::fake()->createWithContent('products.txt', "sku,name\r\nSKU-001,Gadget A\r\n"),
        ], $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, Product::count());
    }

    public function test_oversized_upload_is_rejected(): void
    {
        $this->postJson('/api/v1/imports/preview', [
            'entity' => 'product',
            'file' => UploadedFile::fake()->create('products.csv', 6000),
        ], $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    /*
     * Export.
     */

    public function test_product_export_streams_a_header_row_and_one_row_per_record(): void
    {
        Product::factory()->for($this->company)
            ->count(2)
            ->sequence(
                ['sku' => 'EXP-001', 'name' => 'Exported A'],
                ['sku' => 'EXP-002', 'name' => 'Exported B']
            )
            ->create([
                'category_id' => $this->category->id,
                'brand_id' => $this->brand->id,
                'default_unit_id' => $this->unit->id,
            ]);

        $response = $this->getJson('/api/v1/exports/products', $this->headers());

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringStartsWith('attachment;', $disposition);
        $this->assertStringContainsString('filename=products-', $disposition);
        $this->assertStringEndsWith('.csv', $disposition);

        $content = $response->streamedContent();
        $lines = array_values(array_filter(explode(Csv::EOL, $content)));

        $this->assertCount(3, $lines, 'one header row plus one row per product');

        // The byte order mark heads the file so spreadsheets decode it as UTF-8.
        $this->assertSame(
            'sku,barcode,name,product_type,category,brand,unit,cost_price,selling_price,is_active,track_inventory,reorder_point,minimum_stock,created_at',
            substr($lines[0], strlen(Csv::BOM))
        );
        $this->assertStringStartsWith(Csv::BOM, $content);
        $this->assertSame(1, substr_count($content, 'EXP-001,'));
        $this->assertSame(1, substr_count($content, 'EXP-002,'));
        $this->assertStringContainsString('Exported A', $content);
    }

    public function test_export_honours_the_entity_filters(): void
    {
        Product::factory()->for($this->company)->create(['name' => 'Kept', 'is_active' => true]);
        Product::factory()->for($this->company)->create(['name' => 'Hidden', 'is_active' => false]);

        $content = $this
            ->getJson('/api/v1/exports/products?is_active=1', $this->headers())
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Kept', $content);
        $this->assertStringNotContainsString('Hidden', $content);
    }

    public function test_export_is_scoped_to_the_resolved_company(): void
    {
        Product::factory()->for($this->company)->create(['sku' => 'OURS-001']);
        Product::factory()->for(Company::factory()->create())->create(['sku' => 'THEIRS-001']);

        $content = $this
            ->getJson('/api/v1/exports/products', $this->headers())
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('OURS-001', $content);
        $this->assertStringNotContainsString('THEIRS-001', $content);
    }

    public function test_export_of_a_company_the_user_does_not_belong_to_is_refused(): void
    {
        $other = Company::factory()->create();

        $this->getJson('/api/v1/exports/products?company_id='.$other->id, $this->headers())
            ->assertStatus(403);
    }

    public function test_stock_movement_export_reports_the_ledger(): void
    {
        $product = Product::factory()->for($this->company)->create([
            'sku' => 'LEDGER-001',
            'default_unit_id' => $this->unit->id,
        ]);

        $this->postJson('/api/v1/imports/commit', [
            'entity' => 'opening_stock',
            'file' => $this->csvFile('opening_stock.csv', [
                ['product_sku', 'warehouse_code', 'quantity', 'unit_cost'],
                ['LEDGER-001', 'MAIN', '10', '1500'],
            ]),
        ], $this->headers())->assertStatus(201);

        $content = $this
            ->getJson('/api/v1/exports/stock-movements', $this->headers())
            ->assertOk()
            ->streamedContent();

        $lines = array_values(array_filter(explode(Csv::EOL, $content)));

        $this->assertSame('occurred_at,movement_type,product_sku,product_name,warehouse_code,unit_code,quantity,unit_cost,total_cost,balance_after,reference,created_by,notes', substr($lines[0], strlen(Csv::BOM)));
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('LEDGER-001', $lines[1]);
        $this->assertStringContainsString('opening', $lines[1]);
        $this->assertStringContainsString('10', $lines[1]);

        // The export is audited, so what left the database is verifiable.
        $audit = AuditLog::query()->where('action', 'export.csv')->sole();
        $this->assertSame('stock-movements', $audit->entity_type);
        $this->assertSame(1, $audit->new_values['rows']);
    }

    public function test_stock_export_reports_balances_as_exact_decimals(): void
    {
        $product = Product::factory()->for($this->company)->create([
            'sku' => 'BAL-001',
            'default_unit_id' => $this->unit->id,
        ]);

        $this->postJson('/api/v1/imports/commit', [
            'entity' => 'opening_stock',
            'file' => $this->csvFile('opening_stock.csv', [
                ['product_sku', 'warehouse_code', 'quantity', 'unit_cost'],
                ['BAL-001', 'MAIN', '10', '1500'],
            ]),
        ], $this->headers())->assertStatus(201);

        $content = $this
            ->getJson('/api/v1/exports/stock', $this->headers())
            ->assertOk()
            ->streamedContent();

        $lines = array_values(array_filter(explode(Csv::EOL, $content)));

        $this->assertSame(
            'product_sku,product_name,warehouse_code,warehouse_name,unit_code,on_hand,reserved,available,average_cost,last_cost,last_movement_at',
            substr($lines[0], strlen(Csv::BOM))
        );
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('BAL-001', $lines[1]);
        // No float rounding of a quantity the ledger stores to six places.
        $this->assertStringContainsString(',10.000000,0.000000,10.000000,1500.0000,', $lines[1]);
    }

    public function test_purchase_order_and_goods_receipt_exports_are_available(): void
    {
        $supplier = Supplier::factory()->for($this->company)->create();
        $order = PurchaseOrder::factory()
            ->for($this->company)
            ->for($this->warehouse)
            ->for($supplier)
            ->create(['number' => 'PO-EXPORT-001']);

        GoodsReceipt::factory()
            ->for($this->company)
            ->for($this->warehouse)
            ->for($supplier)
            ->create(['number' => 'GR-EXPORT-001', 'purchase_order_id' => $order->id]);

        $orders = $this->getJson('/api/v1/exports/purchase-orders', $this->headers())->assertOk()->streamedContent();
        $receipts = $this->getJson('/api/v1/exports/goods-receipts', $this->headers())->assertOk()->streamedContent();

        $this->assertStringContainsString('PO-EXPORT-001', $orders);
        $this->assertStringContainsString('GR-EXPORT-001', $receipts);

        foreach (['customers', 'suppliers'] as $entity) {
            $this->getJson('/api/v1/exports/'.$entity, $this->headers())
                ->assertOk()
                ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        }
    }

    public function test_unknown_export_entity_is_rejected(): void
    {
        $this->getJson('/api/v1/exports/invoices', $this->headers())
            ->assertStatus(404);
    }

    public function test_user_without_view_permission_cannot_export(): void
    {
        $reader = $this->restrictedUser(['customers.view']);

        $this->getJson('/api/v1/exports/products', $this->authHeaders($reader))
            ->assertStatus(403);
    }

    public function test_export_supports_only_registered_formats(): void
    {
        $this->getJson('/api/v1/exports/products?format=xlsx', $this->headers())
            ->assertStatus(404);
    }
}
