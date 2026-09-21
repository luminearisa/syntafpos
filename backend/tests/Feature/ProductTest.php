<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Company;
use App\Models\Permission;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductPrice;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\StockAdjustment;
use App\Models\Unit;
use App\Services\InventoryService;
use Tests\TestCase;

class ProductTest extends TestCase
{
    protected InventoryService $inventory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inventory = $this->app->make(InventoryService::class);
    }

    public function test_can_create_product(): void
    {
        $user = $this->authenticatedUser(['products.create', 'products.view']);
        $category = Category::factory()->for($this->company)->create();
        $unit = Unit::factory()->for($this->company)->create();

        $this->postJson('/api/v1/products', [
            'company_id' => $this->company->id,
            'category_id' => $category->id,
            'default_unit_id' => $unit->id,
            'sku' => 'SKU-001',
            'barcode' => '8990000000017',
            'name' => 'Kopi Hitam 200g',
            'product_type' => 'simple',
            'cost_price' => 10000,
            'selling_price' => 15000,
            'reorder_point' => 5,
            'reorder_quantity' => 20,
            'minimum_stock' => 2,
        ], $this->authHeaders($user))
            ->assertCreated()
            ->assertJsonPath('data.sku', 'SKU-001')
            ->assertJsonPath('data.product_type', 'simple')
            ->assertJsonPath('data.reorder_point', '5.000000');

        $this->assertDatabaseHas('products', ['sku' => 'SKU-001', 'company_id' => $this->company->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.create', 'entity_type' => 'product']);
    }

    public function test_sku_is_unique_within_a_company_but_shared_across_companies(): void
    {
        $user = $this->authenticatedUser(['products.create']);
        $other = Company::factory()->create();
        $user->companies()->attach($other->id);

        // The permission travels with a company-scoped role, so grant one in
        // the second company as well.
        $role = Role::create([
            'company_id' => $other->id,
            'name' => 'other_product_admin',
            'display_name' => 'Other Product Admin',
        ]);
        $role->permissions()->sync(Permission::whereIn('name', ['products.create'])->pluck('id')->all());
        $user->roles()->attach($role->id);
        $user->clearPermissionCache($other->id);

        $this->postJson('/api/v1/products', [
            'company_id' => $other->id,
            'sku' => 'SKU-DUP',
            'name' => 'Other Company Product',
        ], $this->authHeaders($user, ['company_id' => $other->id]))
            ->assertCreated();

        // The same code in a different company is nobody's conflict.
        $this->postJson('/api/v1/products', [
            'company_id' => $this->company->id,
            'sku' => 'SKU-DUP',
            'name' => 'Same SKU, Other Company',
        ], $this->authHeaders($user))
            ->assertCreated();

        // ...but within one company it is.
        $this->postJson('/api/v1/products', [
            'company_id' => $other->id,
            'sku' => 'SKU-DUP',
            'name' => 'Duplicate',
        ], $this->authHeaders($user, ['company_id' => $other->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('sku');
    }

    public function test_category_and_unit_must_belong_to_the_product_company(): void
    {
        $user = $this->authenticatedUser(['products.create']);
        $foreignCategory = Category::factory()->for(Company::factory()->create())->create();

        $this->postJson('/api/v1/products', [
            'company_id' => $this->company->id,
            'category_id' => $foreignCategory->id,
            'sku' => 'SKU-CAT',
            'name' => 'Bad Category',
        ], $this->authHeaders($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors('category_id');
    }

    public function test_can_update_and_delete_product(): void
    {
        $user = $this->authenticatedUser(['products.update', 'products.delete']);
        $product = Product::factory()->for($this->company)->create();

        $this->putJson("/api/v1/products/{$product->id}", [
            'name' => 'Renamed Product',
            'reorder_point' => 12,
        ], $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed Product')
            ->assertJsonPath('data.reorder_point', '12.000000');

        $this->assertDatabaseHas('audit_logs', ['action' => 'product.update']);

        $this->deleteJson("/api/v1/products/{$product->id}", [], $this->authHeaders($user))
            ->assertOk();

        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }

    public function test_index_filters_by_search_category_type_and_stock_status(): void
    {
        $user = $this->authenticatedUser(['products.view']);
        $category = Category::factory()->for($this->company)->create();

        $inStock = Product::factory()->for($this->company)->create([
            'sku' => 'TEE-BLUE',
            'name' => 'Blue T-Shirt',
            'category_id' => $category->id,
            'product_type' => 'simple',
            'is_active' => true,
        ]);

        $outOfStock = Product::factory()->for($this->company)->create([
            'sku' => 'MUG-RED',
            'name' => 'Red Mug',
            'category_id' => $category->id,
            'is_active' => false,
        ]);

        $this->receive($inStock, 20, '8000');

        $this->getJson('/api/v1/products?search=Blue', $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.0.sku', 'TEE-BLUE');

        $this->getJson("/api/v1/products?category_id={$category->id}", $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/v1/products?stock_status=out_of_stock', $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.0.sku', 'MUG-RED')
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/products?stock_status=in_stock', $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.0.sku', 'TEE-BLUE');

        $this->getJson('/api/v1/products?is_active=1&product_type=simple', $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.0.sku', 'TEE-BLUE');
    }

    public function test_lookup_finds_a_product_by_barcode_and_by_sku(): void
    {
        $user = $this->authenticatedUser(['products.view']);
        $unit = Unit::factory()->for($this->company)->create();
        $product = Product::factory()->for($this->company)->create([
            'sku' => 'LOOKUP-SKU',
            'barcode' => '8990000000024',
            'default_unit_id' => $unit->id,
            'selling_price' => '17500',
        ]);
        $this->receive($product, 6, '10000');

        // By barcode.
        $this->getJson('/api/v1/products/lookup?barcode=8990000000024', $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.product.sku', 'LOOKUP-SKU')
            ->assertJsonPath('data.variant_id', null)
            ->assertJsonPath('data.unit.code', $unit->code)
            ->assertJsonPath('data.price', '17500.0000')
            ->assertJsonPath('data.stock.on_hand', '6.000000')
            ->assertJsonPath('data.stock.available', '6.000000');

        // By SKU.
        $this->getJson('/api/v1/products/lookup?barcode=LOOKUP-SKU', $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.product.sku', 'LOOKUP-SKU');

        // Unknown code.
        $this->getJson('/api/v1/products/lookup?barcode=NOPE', $this->authHeaders($user))
            ->assertStatus(404);

        // Codes stay inside the company.
        $foreign = Product::factory()->for(Company::factory()->create())->create(['sku' => 'LOOKUP-SKU']);
        $this->getJson('/api/v1/products/lookup?barcode=LOOKUP-SKU', $this->authHeaders($user))
            ->assertStatus(200)
            ->assertJsonPath('data.product.id', $product->id);
    }

    public function test_lookup_resolves_a_variant_barcode(): void
    {
        $user = $this->authenticatedUser(['products.view']);
        $product = Product::factory()->for($this->company)->create(['sku' => 'SHIRT']);
        $variant = ProductVariant::factory()->for($product)->for($this->company)->create([
            'sku' => 'SHIRT-RED-L',
            'selling_price' => '90000',
        ]);
        $barcode = ProductBarcode::factory()->for($product)->for($this->company)->create([
            'product_variant_id' => $variant->id,
            'code' => '4900000000006',
        ]);

        $this->getJson("/api/v1/products/lookup?barcode={$barcode->code}", $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.product.sku', 'SHIRT')
            ->assertJsonPath('data.variant_id', $variant->id)
            ->assertJsonPath('data.variant.sku', 'SHIRT-RED-L')
            ->assertJsonPath('data.price', '90000.0000');
    }

    public function test_can_create_variant_with_attribute_values(): void
    {
        $user = $this->authenticatedUser(['products.update', 'products.view']);
        $product = Product::factory()->for($this->company)->create(['product_type' => 'variable']);
        $attribute = Attribute::factory()->for($this->company)->create();
        $red = AttributeValue::factory()->for($attribute)->create(['name' => 'Red']);
        $large = AttributeValue::factory()->for($attribute)->create(['name' => 'Large']);

        $this->postJson('/api/v1/product-variants', [
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'sku' => 'SHIRT-RED-L',
            'name' => 'Shirt / Red / Large',
            'selling_price' => 90000,
            'attribute_value_ids' => [$red->id, $large->id],
        ], $this->authHeaders($user))
            ->assertCreated()
            ->assertJsonPath('data.sku', 'SHIRT-RED-L')
            ->assertJsonCount(2, 'data.attribute_values');

        $this->assertDatabaseCount('product_variant_attribute_values', 2);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product_variant.create']);

        // Attribute values from another company are refused.
        $foreign = AttributeValue::factory()->for(Attribute::factory()->for(Company::factory()->create())->create())->create();

        $this->postJson('/api/v1/product-variants', [
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'sku' => 'SHIRT-FOREIGN',
            'name' => 'Shirt / Foreign',
            'attribute_value_ids' => [$foreign->id],
        ], $this->authHeaders($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors('attribute_value_ids.0');
    }

    public function test_variant_sku_is_unique_per_company(): void
    {
        $user = $this->authenticatedUser(['products.update']);
        $product = Product::factory()->for($this->company)->create();
        ProductVariant::factory()->for($product)->for($this->company)->create(['sku' => 'VAR-DUP']);

        $this->postJson('/api/v1/product-variants', [
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'sku' => 'VAR-DUP',
            'name' => 'Duplicate',
        ], $this->authHeaders($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors('sku');
    }

    public function test_barcode_is_rejected_when_its_variant_belongs_to_another_product(): void
    {
        $user = $this->authenticatedUser(['products.update', 'products.view']);
        $product = Product::factory()->for($this->company)->create();
        $otherProduct = Product::factory()->for($this->company)->create();
        $otherVariant = ProductVariant::factory()->for($otherProduct)->for($this->company)->create();
        $ownVariant = ProductVariant::factory()->for($product)->for($this->company)->create();

        $this->postJson('/api/v1/product-barcodes', [
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'product_variant_id' => $otherVariant->id,
            'code' => '8990000000031',
            'type' => 'ean',
        ], $this->authHeaders($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors('product_variant_id');

        // The same variant on its own product is accepted.
        $this->postJson('/api/v1/product-barcodes', [
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'product_variant_id' => $ownVariant->id,
            'code' => '8990000000031',
            'type' => 'ean',
        ], $this->authHeaders($user))
            ->assertCreated()
            ->assertJsonPath('data.code', '8990000000031');

        $this->assertDatabaseHas('audit_logs', ['action' => 'barcode.create']);

        // ...and the code stays unique inside the company.
        $this->postJson('/api/v1/product-barcodes', [
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'code' => '8990000000031',
        ], $this->authHeaders($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_price_list_crud_with_date_validation(): void
    {
        $user = $this->authenticatedUser(['price_lists.create', 'price_lists.update', 'price_lists.delete', 'price_lists.view']);

        // end_date before start_date is refused.
        $this->postJson('/api/v1/price-lists', [
            'company_id' => $this->company->id,
            'name' => 'Lebaran Promo',
            'start_date' => '2026-06-01',
            'end_date' => '2026-05-01',
        ], $this->authHeaders($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors('end_date');

        // A valid window is accepted.
        $this->postJson('/api/v1/price-lists', [
            'company_id' => $this->company->id,
            'name' => 'Lebaran Promo',
            'currency' => 'IDR',
            'start_date' => '2026-05-01',
            'end_date' => '2026-06-01',
            'is_default' => true,
        ], $this->authHeaders($user))
            ->assertCreated()
            ->assertJsonPath('data.name', 'Lebaran Promo');

        $priceList = PriceList::query()->where('name', 'Lebaran Promo')->first();

        $this->assertDatabaseHas('audit_logs', ['action' => 'price.change', 'entity_type' => 'price_list']);

        // Name stays unique per company.
        $this->postJson('/api/v1/price-lists', [
            'company_id' => $this->company->id,
            'name' => 'Lebaran Promo',
        ], $this->authHeaders($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->putJson("/api/v1/price-lists/{$priceList->id}", ['name' => 'Natal Promo'], $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.name', 'Natal Promo');

        $this->deleteJson("/api/v1/price-lists/{$priceList->id}", [], $this->authHeaders($user))
            ->assertOk();

        $this->assertSoftDeleted('price_lists', ['id' => $priceList->id]);
    }

    public function test_price_line_requires_product_in_the_price_list_company(): void
    {
        $user = $this->authenticatedUser(['products.update', 'products.view']);
        $priceList = PriceList::factory()->for($this->company)->create();
        $product = Product::factory()->for($this->company)->create();
        $foreignProduct = Product::factory()->for(Company::factory()->create())->create();

        $this->postJson('/api/v1/product-prices', [
            'price_list_id' => $priceList->id,
            'product_id' => $foreignProduct->id,
            'price_type' => 'retail',
            'price' => 12500,
        ], $this->authHeaders($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors('product_id');

        $this->postJson('/api/v1/product-prices', [
            'price_list_id' => $priceList->id,
            'product_id' => $product->id,
            'price_type' => 'retail',
            'price' => 12500,
            'minimum_price' => 10000,
        ], $this->authHeaders($user))
            ->assertCreated()
            ->assertJsonPath('data.price', '12500.0000');

        $this->assertDatabaseHas('audit_logs', ['action' => 'price.change', 'entity_type' => 'product_price']);

        // One price type per product and price list.
        $this->postJson('/api/v1/product-prices', [
            'price_list_id' => $priceList->id,
            'product_id' => $product->id,
            'price_type' => 'retail',
            'price' => 13000,
        ], $this->authHeaders($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors('price_type');

        // A second price type is fine.
        $this->postJson('/api/v1/product-prices', [
            'price_list_id' => $priceList->id,
            'product_id' => $product->id,
            'price_type' => 'wholesale',
            'price' => 11000,
        ], $this->authHeaders($user))
            ->assertCreated();

        $this->assertDatabaseCount('product_prices', 2);
    }

    public function test_product_margin_is_computed_from_real_stock(): void
    {
        $user = $this->authenticatedUser(['products.view']);
        $unit = Unit::factory()->for($this->company)->create();
        $product = Product::factory()->for($this->company)->create([
            'default_unit_id' => $unit->id,
            'selling_price' => '15000',
            'cost_price' => '9000',
            'reorder_point' => 5,
        ]);

        // Nothing has moved yet: every figure is a real zero.
        $this->getJson("/api/v1/products/{$product->id}", $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.stock.on_hand', '0.000000')
            ->assertJsonPath('data.stock.status', 'out_of_stock')
            ->assertJsonPath('data.costing.average_cost', '0')
            ->assertJsonPath('data.costing.last_purchase_cost', '0')
            ->assertJsonPath('data.costing.estimated_margin', '0');

        // 100 in at 10.000 and 50 in at 12.000 -> weighted average 10.666.67.
        $this->receive($product, 100, '10000');
        $this->receive($product, 50, '12000');

        $this->getJson("/api/v1/products/{$product->id}", $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.stock.on_hand', '150.000000')
            ->assertJsonPath('data.stock.status', 'in_stock')
            ->assertJsonPath('data.costing.average_cost', '10666.6667')
            ->assertJsonPath('data.costing.last_purchase_cost', '12000.0000')
            ->assertJsonPath('data.costing.current_selling_price', '15000.0000')
            ->assertJsonPath('data.costing.estimated_margin', '4333.3333')
            ->assertJsonPath('data.costing.estimated_margin_percent', '28.89');
    }

    public function test_user_without_permission_is_denied(): void
    {
        $user = $this->authenticatedUser(['products.view']);
        $product = Product::factory()->for($this->company)->create();

        $this->postJson('/api/v1/products', [
            'company_id' => $this->company->id,
            'sku' => 'NO-PERM',
            'name' => 'Forbidden',
        ], $this->authHeaders($user))
            ->assertForbidden();

        $this->putJson("/api/v1/products/{$product->id}", ['name' => 'Forbidden'], $this->authHeaders($user))
            ->assertForbidden();

        $this->deleteJson("/api/v1/products/{$product->id}", [], $this->authHeaders($user))
            ->assertForbidden();

        // Another company's product is not visible at all.
        $foreign = Product::factory()->for(Company::factory()->create())->create();

        $this->getJson("/api/v1/products/{$foreign->id}", $this->authHeaders($user))
            ->assertForbidden();
    }

    public function test_product_price_lines_are_scoped_to_the_price_list_company(): void
    {
        $user = $this->authenticatedUser(['products.view']);
        $foreignCompany = Company::factory()->create();
        $priceList = PriceList::factory()->for($foreignCompany)->create();
        $price = ProductPrice::factory()->for($priceList)->for(Product::factory()->for($foreignCompany))->create();

        $this->getJson('/api/v1/product-prices', $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->getJson("/api/v1/product-prices/{$price->id}", $this->authHeaders($user))
            ->assertForbidden();
    }

    /**
     * Receive stock through the only path that is allowed to move it, so the
     * balances the resources report are the real ledger.
     */
    private function receive(Product $product, int $quantity, string $unitCost): void
    {
        $unit = $product->defaultUnit ?: Unit::factory()->for($product->company)->create();
        $product->defaultUnit()->associate($unit);
        $product->save();

        $reference = StockAdjustment::factory()
            ->for($product->company)
            ->for($this->warehouse)
            ->create();

        $this->inventory->move(
            [
                'product_id' => $product->id,
                'product_variant_id' => null,
                'unit_id' => $unit->id,
                'quantity' => (string) $quantity,
            ],
            MovementType::Purchase,
            [
                'company_id' => $product->company_id,
                'branch_id' => $this->branch->id,
                'warehouse_id' => $this->warehouse->id,
                'location_id' => null,
            ],
            $reference,
            $unitCost
        );
    }
}
