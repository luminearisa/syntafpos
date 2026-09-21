<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\PosCart;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductPrice;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\StockBalance;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Tests\TestCase;

/**
 * The till's read side: what may be sold, at what price, with what on the shelf.
 *
 * These tests cover what the cashier's screen is built from — the search box,
 * the category strip, and the single "found / not found" answer a barcode
 * scanner needs. Prices come from the Phase 2 engine, never from the till.
 */
class PosProductSearchTest extends TestCase
{
    protected User $cashier;

    protected Unit $unit;

    protected Product $coffee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashier = $this->authenticatedUser(['pos.view', 'pos.transact', 'pos.hold']);
        $this->unit = Unit::factory()->for($this->company)->create(['code' => 'PCS']);

        // A fixed catalogue price lets a test tell "shelf price" apart from a
        // tiered one without guessing at factory randoms.
        $this->coffee = $this->sellableProduct([
            'sku' => 'COF-001',
            'barcode' => '8991000000011',
            'name' => 'Coffee House Blend',
            'selling_price' => '25000.0000',
        ]);
    }

    protected function sellableProduct(array $attributes = []): Product
    {
        return Product::factory()->for($this->company)->create(array_merge([
            'default_unit_id' => $this->unit->id,
            'is_sellable' => true,
            'is_active' => true,
            'track_inventory' => false,
        ], $attributes));
    }

    /**
     * The headers a real till sends: the register decides both the price tier
     * and which warehouse's stock the grid may promise.
     */
    protected function headers(): array
    {
        return $this->authHeaders($this->cashier, $this->context());
    }

    protected function context(): array
    {
        return [
            'branch_id' => $this->branch->id,
            'warehouse_id' => $this->warehouse->id,
            'register_id' => $this->register->id,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function search(array $query = []): array
    {
        $response = $this->getJson('/api/v1/pos/products/search?'.http_build_query($query), $this->headers());
        $response->assertOk();
        $this->assertTrue($response->json('success'));

        return $response->json('data');
    }

    protected function defaultList(array $attributes = []): PriceList
    {
        return PriceList::factory()->for($this->company)->create(array_merge([
            'name' => 'Standard Retail',
            'status' => 'active',
            'is_default' => true,
            'start_date' => null,
            'end_date' => null,
        ], $attributes));
    }

    /**
     * A second user with only the listed permissions, inside this company.
     */
    protected function cashierWith(array $permissions, ?Company $in = null): User
    {
        $company = $in ?? $this->company;
        $user = User::factory()->create();
        $user->companies()->attach($company->id);

        $role = Role::create([
            'company_id' => $company->id,
            'name' => 'pos_role_'.uniqid(),
            'display_name' => 'POS Role',
        ]);
        $role->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id')->all());
        $user->roles()->attach($role->id);
        $user->clearPermissionCache($company->id);

        return $user;
    }

    //
    // Searching
    //

    public function test_name_sku_and_barcode_all_match_one_search_box(): void
    {
        foreach (['House', 'COF-001', '8991000000011'] as $term) {
            $this->assertSame(
                [$this->coffee->id],
                array_column($this->search(['search' => $term]), 'product_id'),
                "search term {$term} should find the product"
            );
        }
    }

    public function test_a_category_or_brand_name_in_the_box_finds_the_product(): void
    {
        $category = Category::create([
            'company_id' => $this->company->id,
            'code' => 'ROAST',
            'name' => 'Roastery',
            'status' => 'active',
        ]);
        $brand = Brand::create([
            'company_id' => $this->company->id,
            'code' => 'LARAZ',
            'name' => 'Kopi Laraz',
            'status' => 'active',
        ]);
        $this->coffee->forceFill(['category_id' => $category->id, 'brand_id' => $brand->id])->save();

        $this->assertSame([
            $this->coffee->id,
        ], array_column($this->search(['search' => 'Roastery']), 'product_id'));
        $this->assertSame([
            $this->coffee->id,
        ], array_column($this->search(['search' => 'Kopi']), 'product_id'));
    }

    public function test_a_variants_code_finds_its_parent_product(): void
    {
        $variant = ProductVariant::factory()->for($this->coffee)->create([
            'company_id' => $this->company->id,
            'sku' => 'COF-001-BLUE',
            'barcode' => '8991000000099',
        ]);

        // Variant codes match whole: a scanner sends the full code, and a LIKE
        // over these would turn every partial keystroke into a different row.
        $this->assertSame([
            $variant->product_id,
        ], array_column($this->search(['search' => 'COF-001-BLUE']), 'product_id'));
        $this->assertSame([], array_column($this->search(['search' => 'COF-001-BL']), 'product_id'));
    }

    public function test_the_category_strip_filters_the_grid(): void
    {
        $beans = Category::create([
            'company_id' => $this->company->id,
            'code' => 'BEANS',
            'name' => 'Beans',
            'status' => 'active',
        ]);
        $this->coffee->forceFill(['category_id' => $beans->id])->save();
        $this->sellableProduct(['name' => 'Ceramic Mug']);

        $this->assertSame([$this->coffee->id], array_column($this->search(['category_id' => $beans->id]), 'product_id'));
        $this->assertCount(2, $this->search());
    }

    public function test_products_that_cannot_be_sold_never_appear(): void
    {
        $this->sellableProduct(['name' => 'Warehouse Glue', 'is_sellable' => false]);
        $this->sellableProduct(['name' => 'Discontinued Syrup', 'is_active' => false]);
        $this->sellableProduct(['name' => 'House Blend Decaf']);

        $names = array_column($this->search(), 'name');

        $this->assertNotContains('Warehouse Glue', $names);
        $this->assertNotContains('Discontinued Syrup', $names);
        $this->assertContains('House Blend Decaf', $names);
    }

    public function test_the_grid_is_paged_so_a_long_catalogue_stays_fast(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->sellableProduct(['name' => 'Water Bottle '.$i]);
        }

        $response = $this->getJson('/api/v1/pos/products/search?per_page=2&page=2', $this->headers());
        $response->assertOk();

        $this->assertSame(2, $response->json('meta.per_page'));
        $this->assertSame(6, $response->json('meta.total'));
        $this->assertSame(2, $response->json('meta.current_page'));
        $this->assertCount(2, $response->json('data'));
    }

    //
    // Pricing
    //

    public function test_a_row_carries_the_price_and_says_where_it_came_from(): void
    {
        $row = $this->search(['search' => 'House Blend'])[0];

        $this->assertSame('25000.0000', $row['price']);
        $this->assertSame('product', $row['price_source']);
        $this->assertNull($row['price_list_id']);
        // The catalogue figure stays visible so a cashier can see the gap.
        $this->assertSame('25000.0000', $row['catalogue_price']);
    }

    public function test_a_price_list_reshapes_the_grid(): void
    {
        $list = $this->defaultList();
        ProductPrice::create([
            'price_list_id' => $list->id,
            'product_id' => $this->coffee->id,
            'price_type' => 'retail',
            'price' => '22000.0000',
        ]);

        $row = $this->search(['search' => 'House Blend'])[0];

        $this->assertSame('22000.0000', $row['price']);
        $this->assertSame('default_list', $row['price_source']);
        $this->assertSame($list->id, $row['price_list_id']);
        $this->assertSame('25000.0000', $row['catalogue_price']);
    }

    public function test_a_worked_customer_switches_the_grid_to_their_tier(): void
    {
        $customer = Customer::factory()->for($this->company)->create(['name' => 'Toko Sembako']);
        $list = $this->defaultList(['name' => 'Reseller Rate', 'is_default' => false]);
        $customer->forceFill(['price_list_id' => $list->id])->save();
        ProductPrice::create([
            'price_list_id' => $list->id,
            'product_id' => $this->coffee->id,
            'price_type' => 'reseller',
            'price' => '19000.0000',
        ]);

        // The tier follows the customer on the cart the cashier is working,
        // which is what keeps the grid and checkout from disagreeing.
        PosCart::factory()->for($this->company)->create([
            'user_id' => $this->cashier->id,
            'branch_id' => $this->branch->id,
            'warehouse_id' => $this->warehouse->id,
            'register_id' => $this->register->id,
            'customer_id' => $customer->id,
        ]);

        $row = $this->search(['search' => 'House Blend'])[0];

        $this->assertSame('19000.0000', $row['price']);
        $this->assertSame('price_list', $row['price_source']);
    }

    public function test_an_expired_price_list_does_not_reach_the_till(): void
    {
        $list = $this->defaultList([
            'name' => 'Last Years Promo',
            'start_date' => now()->subMonths(8)->toDateString(),
            'end_date' => now()->subMonth()->toDateString(),
        ]);
        ProductPrice::create([
            'price_list_id' => $list->id,
            'product_id' => $this->coffee->id,
            'price_type' => 'retail',
            'price' => '1.0000',
        ]);

        $this->assertSame('25000.0000', $this->search(['search' => 'House Blend'])[0]['price']);
    }

    public function test_money_and_stock_leave_the_server_as_decimal_strings(): void
    {
        $row = $this->search(['search' => 'House Blend'])[0];

        $this->assertIsString($row['price']);
        $this->assertIsString($row['stock']['on_hand']);
        $this->assertIsString($row['stock']['available']);
    }

    //
    // Stock, read only
    //

    public function test_availability_reflects_the_registers_warehouse(): void
    {
        $this->coffee->forceFill(['track_inventory' => true, 'reorder_point' => '10'])->save();

        StockBalance::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->coffee->id,
            'unit_id' => $this->unit->id,
            'on_hand' => '40.000000',
            'reserved' => '5.000000',
        ]);
        // A back-room warehouse this register does not sell from.
        $back = Warehouse::factory()->for($this->company)->for($this->branch)->create(['code' => 'BACK']);
        StockBalance::create([
            'company_id' => $this->company->id,
            'warehouse_id' => $back->id,
            'product_id' => $this->coffee->id,
            'unit_id' => $this->unit->id,
            'on_hand' => '900.000000',
        ]);

        $stock = $this->search(['search' => 'House Blend'])[0]['stock'];

        $this->assertSame('40.000000', $stock['on_hand']);
        $this->assertSame('35.000000', $stock['available']);
        $this->assertTrue($stock['tracked']);
        $this->assertFalse($stock['low']);
    }

    //
    // Scanning
    //

    public function test_a_scanned_code_answers_with_one_product_ready_to_add(): void
    {
        $response = $this->getJson('/api/v1/pos/products/search?barcode=8991000000011', $this->headers());
        $response->assertOk();

        $this->assertSame('8991000000011', $response->json('data.matched_code'));
        $this->assertSame($this->coffee->id, $response->json('data.product.product_id'));
        $this->assertSame('25000.0000', $response->json('data.product.price'));
        // The payload the till posts straight back to the cart.
        $this->assertSame([
            'product_id' => $this->coffee->id,
            'quantity' => '1',
        ], $response->json('data.add_to_cart'));
    }

    public function test_a_scanned_variant_code_carries_that_variant(): void
    {
        $variant = ProductVariant::factory()->for($this->coffee)->create([
            'company_id' => $this->company->id,
            'sku' => 'COF-001-BLUE',
            'barcode' => '8991000000022',
            'selling_price' => '27500.0000',
        ]);

        $response = $this->getJson('/api/v1/pos/products/search?barcode=8991000000022', $this->headers());
        $response->assertOk();

        $this->assertSame($variant->id, $response->json('data.variant_id'));
        $this->assertSame($variant->id, $response->json('data.add_to_cart.product_variant_id'));
        $this->assertSame('27500.0000', $response->json('data.product.variants.0.selling_price'));
    }

    public function test_a_secondary_barcode_resolves_to_its_product(): void
    {
        ProductBarcode::create([
            'company_id' => $this->company->id,
            'product_id' => $this->coffee->id,
            'code' => 'MRD-998877',
            'type' => 'internal',
        ]);

        $response = $this->getJson('/api/v1/pos/products/search?barcode=MRD-998877', $this->headers());
        $response->assertOk();
        $this->assertSame($this->coffee->id, $response->json('data.product.product_id'));
    }

    public function test_an_unknown_code_is_a_clear_not_found(): void
    {
        $response = $this->getJson('/api/v1/pos/products/search?barcode=0000000000000', $this->headers());

        $response->assertNotFound();
        $this->assertFalse($response->json('success'));
        $this->assertSame('Barcode 0000000000000 was not found.', $response->json('message'));
    }

    public function test_a_code_on_a_non_sellable_product_is_refused(): void
    {
        $hidden = $this->sellableProduct(['name' => 'Staff Uniform', 'barcode' => '8991000000555', 'is_sellable' => false]);

        $response = $this->getJson('/api/v1/pos/products/search?barcode='.$hidden->barcode, $this->headers());

        $response->assertStatus(422);
        // Named, not "not found": a barred item must not read like a misread
        // scanner, or the cashier scans it again.
        $this->assertStringContainsString('cannot be sold', $response->json('message'));
        $this->assertStringContainsString('Staff Uniform', $response->json('message'));
    }

    public function test_a_scan_never_degrades_into_the_fuzzy_grid(): void
    {
        // If it did, a scanned SKU would return a list to pick from.
        $response = $this->getJson('/api/v1/pos/products/search?barcode=COF-001', $this->headers());
        $response->assertOk();

        $this->assertArrayHasKey('matched_code', $response->json('data'));
    }

    //
    // Access
    //

    public function test_search_needs_a_pos_permission(): void
    {
        $bystander = $this->cashierWith(['products.view']);

        $this->getJson('/api/v1/pos/products/search', $this->authHeaders($bystander))
            ->assertForbidden();
    }

    public function test_one_company_cannot_see_anothers_catalogue(): void
    {
        $theirs = Company::factory()->create();
        $outsider = $this->cashierWith(['pos.view'], $theirs);
        $headers = $this->authHeaders($outsider, ['company_id' => $theirs->id]);

        $this->getJson('/api/v1/pos/products/search?search=Coffee', $headers)
            ->assertOk()
            ->assertJsonCount(0, 'data');

        Product::factory()->for($theirs)->create(['name' => 'Their Coffee', 'is_sellable' => true, 'is_active' => true]);

        $response = $this->getJson('/api/v1/pos/products/search?search=Coffee', $headers);
        $response->assertOk();
        $this->assertSame(['Their Coffee'], array_column($response->json('data'), 'name'));
    }

    public function test_a_foreign_cart_id_cannot_be_read(): void
    {
        // Route binding is company-blind, so this is the isolation check that
        // matters: the id of someone else's draft must resolve to nothing.
        $cart = PosCart::factory()->for($this->company)->create(['user_id' => $this->cashier->id]);
        $theirs = Company::factory()->create();
        $outsider = $this->cashierWith(['pos.view', 'pos.transact', 'pos.hold'], $theirs);

        $this->getJson('/api/v1/pos/cart/'.$cart->id, $this->authHeaders($outsider, ['company_id' => $theirs->id]))
            ->assertForbidden();
    }
}
