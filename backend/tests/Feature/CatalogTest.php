<?php

namespace Tests\Feature;

use App\Models\Attribute;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tax;
use App\Models\Unit;
use App\Models\UnitConversion;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    public function test_category_can_be_created_with_parent_and_level(): void
    {
        $user = $this->authenticatedUser(['categories.create', 'categories.view']);

        $root = $this->createCategory($user, ['code' => 'CAT-ROOT', 'name' => 'Beverages']);
        $child = $this->createCategory($user, ['code' => 'CAT-CHILD', 'name' => 'Coffee', 'parent_id' => $root->json('data.id')]);
        $grandchild = $this->createCategory($user, ['code' => 'CAT-GRAND', 'name' => 'Beans', 'parent_id' => $child->json('data.id')]);

        $child->assertCreated()->assertJsonPath('data.level', 1);
        $grandchild->assertCreated()->assertJsonPath('data.level', 2);

        $this->assertDatabaseHas('audit_logs', ['action' => 'category.create']);
    }

    public function test_category_parent_from_another_company_is_rejected(): void
    {
        $user = $this->authenticatedUser(['categories.create']);
        $other = Category::factory()->for(Company::factory()->create())->create();

        $this->createCategory($user, ['code' => 'CAT-X', 'name' => 'X', 'parent_id' => $other->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('parent_id');
    }

    public function test_category_index_returns_a_tree(): void
    {
        $user = $this->authenticatedUser(['categories.create', 'categories.view']);

        $root = $this->createCategory($user, ['code' => 'TREE-ROOT', 'name' => 'Food']);
        $child = $this->createCategory($user, ['code' => 'TREE-CHILD', 'name' => 'Snacks', 'parent_id' => $root->json('data.id')]);
        $this->createCategory($user, ['code' => 'TREE-GRAND', 'name' => 'Chips', 'parent_id' => $child->json('data.id')]);
        $this->createCategory($user, ['code' => 'TREE-OTHER', 'name' => 'Drinks']);

        $response = $this->getJson('/api/v1/categories', $this->authHeaders($user))->assertOk();

        $roots = collect($response->json('data'));

        // A flat list would return four rows; a tree returns two roots.
        $this->assertCount(2, $roots);

        $food = $roots->firstWhere('code', 'TREE-ROOT');
        $this->assertSame('TREE-CHILD', $food['children'][0]['code']);
        $this->assertSame('TREE-GRAND', $food['children'][0]['children'][0]['code']);
        $this->assertSame(0, $food['level']);
        $this->assertSame(2, $food['children'][0]['children'][0]['level']);
    }

    public function test_moving_a_category_under_its_own_descendant_is_rejected(): void
    {
        $user = $this->authenticatedUser(['categories.create', 'categories.update', 'categories.view']);

        $root = $this->createCategory($user, ['code' => 'CY-ROOT', 'name' => 'A']);
        $child = $this->createCategory($user, ['code' => 'CY-CHILD', 'name' => 'B', 'parent_id' => $root->json('data.id')]);
        $grandchild = $this->createCategory($user, ['code' => 'CY-GRAND', 'name' => 'C', 'parent_id' => $child->json('data.id')]);

        $this->putJson("/api/v1/categories/{$root->json('data.id')}", [
            'parent_id' => $grandchild->json('data.id'),
        ], $this->authHeaders($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors('parent_id')
            ->assertJsonPath('message', 'A category cannot be moved under one of its own descendants.');

        $this->assertDatabaseHas('categories', [
            'id' => $root->json('data.id'),
            'parent_id' => null,
        ]);
    }

    public function test_moving_a_category_relevels_its_subtree(): void
    {
        $user = $this->authenticatedUser(['categories.create', 'categories.update']);

        $root = $this->createCategory($user, ['code' => 'MV-ROOT', 'name' => 'Root']);
        $child = $this->createCategory($user, ['code' => 'MV-CHILD', 'name' => 'Child', 'parent_id' => $root->json('data.id')]);
        $grandchild = $this->createCategory($user, ['code' => 'MV-GRAND', 'name' => 'Grand', 'parent_id' => $child->json('data.id')]);

        $otherRoot = $this->createCategory($user, ['code' => 'MV-OTHER', 'name' => 'Other']);

        $this->putJson("/api/v1/categories/{$grandchild->json('data.id')}", [
            'parent_id' => $otherRoot->json('data.id'),
        ], $this->authHeaders($user))->assertOk();

        $this->assertDatabaseHas('categories', [
            'id' => $grandchild->json('data.id'),
            'parent_id' => $otherRoot->json('data.id'),
            'level' => 1,
        ]);
    }

    public function test_category_crud_and_soft_delete(): void
    {
        $user = $this->authenticatedUser(['categories.create', 'categories.update', 'categories.delete', 'categories.view']);

        $created = $this->createCategory($user, ['code' => 'CRUD-CAT', 'name' => 'Toys']);
        $id = $created->json('data.id');

        $this->putJson("/api/v1/categories/{$id}", ['name' => 'Games'], $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.name', 'Games');

        $this->getJson("/api/v1/categories/{$id}", $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.code', 'CRUD-CAT');

        $this->deleteJson("/api/v1/categories/{$id}", [], $this->authHeaders($user))->assertOk();

        $this->assertSoftDeleted('categories', ['id' => $id]);
    }

    public function test_brand_crud_and_code_unique_per_company(): void
    {
        $user = $this->authenticatedUser(['brands.create', 'brands.update', 'brands.delete', 'brands.view']);
        $brand = Brand::factory()->for($this->company)->create();

        $this->postJson('/api/v1/brands', [
            'company_id' => $this->company->id,
            'code' => $brand->code,
            'name' => 'Duplicate',
        ], $this->authHeaders($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $created = $this->postJson('/api/v1/brands', [
            'company_id' => $this->company->id,
            'code' => 'BRD-NEW',
            'name' => 'Acme',
        ], $this->authHeaders($user))
            ->assertCreated()
            ->assertJsonPath('data.code', 'BRD-NEW');

        $id = $created->json('data.id');

        $this->putJson("/api/v1/brands/{$id}", ['name' => 'Acme Co'], $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.name', 'Acme Co');

        $this->getJson('/api/v1/brands', $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->deleteJson("/api/v1/brands/{$id}", [], $this->authHeaders($user))->assertOk();

        $this->assertSoftDeleted('brands', ['id' => $id]);

        // The same code stays free under a second company the user can reach.
        $otherCompany = Company::factory()->create();
        $user->companies()->attach($otherCompany->id);
        $otherRole = Role::create([
            'company_id' => $otherCompany->id,
            'name' => 'brands_other',
            'display_name' => 'Brands Other',
        ]);
        $otherRole->permissions()->sync(Permission::whereIn('name', ['brands.create'])->pluck('id')->all());
        $user->roles()->attach($otherRole->id);
        $user->clearPermissionCache($otherCompany->id);

        $this->postJson('/api/v1/brands', [
            'company_id' => $otherCompany->id,
            'code' => $brand->code,
            'name' => 'Other Co',
        ], $this->authHeaders($user, ['company_id' => $otherCompany->id]))->assertCreated();
    }

    public function test_unit_crud_and_base_unit_is_unique_per_type(): void
    {
        $user = $this->authenticatedUser(['units.create', 'units.update', 'units.delete', 'units.view']);

        $first = $this->postJson('/api/v1/units', [
            'company_id' => $this->company->id,
            'name' => 'Pieces',
            'code' => 'PCS',
            'type' => 'quantity',
            'is_base' => true,
        ], $this->authHeaders($user))
            ->assertCreated()
            ->assertJsonPath('data.is_base', true);

        $this->postJson('/api/v1/units', [
            'company_id' => $this->company->id,
            'name' => 'Carton',
            'code' => 'CTN',
            'type' => 'quantity',
            'is_base' => true,
        ], $this->authHeaders($user))
            ->assertCreated()
            ->assertJsonPath('data.is_base', true);

        // Claiming the base flag clears it on the previous base unit.
        $this->assertDatabaseHas('units', ['id' => $first->json('data.id'), 'is_base' => false]);

        $this->putJson("/api/v1/units/{$first->json('data.id')}", ['is_base' => true], $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.is_base', true);

        $this->assertDatabaseHas('units', ['code' => 'CTN', 'is_base' => false]);

        $this->deleteJson("/api/v1/units/{$first->json('data.id')}", [], $this->authHeaders($user))->assertOk();
        $this->assertSoftDeleted('units', ['id' => $first->json('data.id')]);
    }

    public function test_unit_conversion_crud_validation_and_math(): void
    {
        $user = $this->authenticatedUser(['units.create', 'units.view', 'units.update', 'units.delete']);

        $pcs = $this->createUnit($user, 'PCS', 'Pieces');
        $ctn = $this->createUnit($user, 'CTN', 'Carton');

        // Same unit on both ends is meaningless.
        $this->postJson('/api/v1/unit-conversions', [
            'company_id' => $this->company->id,
            'from_unit_id' => $pcs,
            'to_unit_id' => $pcs,
            'factor' => '1',
        ], $this->authHeaders($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors('to_unit_id');

        // A factor must multiply, never divide.
        $this->postJson('/api/v1/unit-conversions', [
            'company_id' => $this->company->id,
            'from_unit_id' => $ctn,
            'to_unit_id' => $pcs,
            'factor' => '0',
        ], $this->authHeaders($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors('factor');

        $conversion = $this->postJson('/api/v1/unit-conversions', [
            'company_id' => $this->company->id,
            'from_unit_id' => $ctn,
            'to_unit_id' => $pcs,
            'factor' => '24.0000000000',
        ], $this->authHeaders($user))
            ->assertCreated()
            ->assertJsonPath('data.factor', '24.0000000000');

        $id = $conversion->json('data.id');

        $this->putJson("/api/v1/unit-conversions/{$id}", ['factor' => '12.0000000000'], $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.factor', '12.0000000000');

        $this->deleteJson("/api/v1/unit-conversions/{$id}", [], $this->authHeaders($user))->assertOk();
        $this->assertDatabaseMissing('unit_conversions', ['id' => $id]);
    }

    public function test_convert_endpoint_multiplies_with_bcmath(): void
    {
        $user = $this->authenticatedUser(['units.create', 'units.view']);

        $pcs = $this->createUnit($user, 'PCS', 'Pieces');
        $ctn = $this->createUnit($user, 'CTN', 'Carton');

        UnitConversion::create([
            'company_id' => $this->company->id,
            'from_unit_id' => $ctn,
            'to_unit_id' => $pcs,
            'factor' => '24',
        ]);

        $response = $this->getJson("/api/v1/units/{$ctn}/convert?to={$pcs}&quantity=2.500000", $this->authHeaders($user))
            ->assertOk();

        // 2.5 cartons * 24, exact at the column's 10-digit scale.
        $this->assertSame('60.0000000000', $response->json('data.result'));
        $this->assertSame('2.500000', $response->json('data.quantity'));
        $this->assertSame('24.0000000000', $response->json('data.factor'));
        $this->assertSame((int) $pcs, $response->json('data.to_unit_id'));

        // Same unit needs no row: the identity conversion.
        $this->getJson("/api/v1/units/{$ctn}/convert?to={$ctn}&quantity=7.000000", $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.result', '7.000000');

        // The reverse direction is a separate row by design, so it is absent.
        $this->getJson("/api/v1/units/{$pcs}/convert?to={$ctn}&quantity=10.000000", $this->authHeaders($user))
            ->assertStatus(404)
            ->assertJsonPath('success', false);

        $this->getJson("/api/v1/units/{$ctn}/convert?to=999999&quantity=10.000000", $this->authHeaders($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors('to');
    }

    public function test_tax_crud_and_unique_code_per_company(): void
    {
        $user = $this->authenticatedUser(['taxes.create', 'taxes.update', 'taxes.delete', 'taxes.view']);
        $tax = Tax::factory()->for($this->company)->create();

        $this->postJson('/api/v1/taxes', [
            'company_id' => $this->company->id,
            'code' => $tax->code,
            'name' => 'Duplicate',
            'rate' => '5',
        ], $this->authHeaders($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $this->postJson('/api/v1/taxes', [
            'company_id' => $this->company->id,
            'code' => 'VAT',
            'name' => 'Value Added Tax',
            'rate' => '11.12345',
            'type' => 'exclusive',
        ], $this->authHeaders($user))
            ->assertStatus(422) // rate keeps 4 fractional digits, no more.
            ->assertJsonValidationErrors('rate');

        $created = $this->postJson('/api/v1/taxes', [
            'company_id' => $this->company->id,
            'code' => 'VAT',
            'name' => 'Value Added Tax',
            'rate' => '11.5000',
            'type' => 'exclusive',
        ], $this->authHeaders($user))
            ->assertCreated()
            ->assertJsonPath('data.rate', '11.5000');

        $this->putJson("/api/v1/taxes/{$created->json('data.id')}", ['rate' => '12.0000'], $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.rate', '12.0000');

        $this->deleteJson("/api/v1/taxes/{$created->json('data.id')}", [], $this->authHeaders($user))->assertOk();
        $this->assertSoftDeleted('taxes', ['id' => $created->json('data.id')]);
    }

    public function test_attribute_and_attribute_value_crud(): void
    {
        $user = $this->authenticatedUser(['attributes.create', 'attributes.update', 'attributes.delete', 'attributes.view']);

        $attribute = $this->postJson('/api/v1/attributes', [
            'company_id' => $this->company->id,
            'name' => 'Size',
            'display_type' => 'select',
        ], $this->authHeaders($user))
            ->assertCreated()
            ->assertJsonPath('data.name', 'Size');

        $attributeId = $attribute->json('data.id');

        $this->postJson('/api/v1/attributes', [
            'company_id' => $this->company->id,
            'name' => 'Size',
        ], $this->authHeaders($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $value = $this->postJson('/api/v1/attribute-values', [
            'company_id' => $this->company->id,
            'attribute_id' => $attributeId,
            'name' => 'Large',
            'color' => '#FF0000',
        ], $this->authHeaders($user))
            ->assertCreated()
            ->assertJsonPath('data.attribute_id', $attributeId);

        // A value cannot be attached to an attribute of another company.
        $otherAttribute = Attribute::factory()->for(Company::factory()->create())->create();
        $this->postJson('/api/v1/attribute-values', [
            'company_id' => $this->company->id,
            'attribute_id' => $otherAttribute->id,
            'name' => 'Foreign',
        ], $this->authHeaders($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors('attribute_id');

        $this->putJson("/api/v1/attribute-values/{$value->json('data.id')}", ['name' => 'Extra Large'], $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.name', 'Extra Large');

        // Values travel with their attribute.
        $this->getJson("/api/v1/attributes/{$attributeId}", $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.values.0.name', 'Extra Large');

        $this->deleteJson("/api/v1/attribute-values/{$value->json('data.id')}", [], $this->authHeaders($user))->assertOk();
        $this->assertDatabaseMissing('attribute_values', ['id' => $value->json('data.id')]);

        $this->deleteJson("/api/v1/attributes/{$attributeId}", [], $this->authHeaders($user))->assertOk();
        $this->assertSoftDeleted('attributes', ['id' => $attributeId]);
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        // Holds one catalog permission only, so the others must be denied.
        $user = $this->authenticatedUser(['brands.view']);

        $this->getJson('/api/v1/categories', $this->authHeaders($user))->assertStatus(403);
        $this->getJson('/api/v1/units', $this->authHeaders($user))->assertStatus(403);
        $this->getJson('/api/v1/taxes', $this->authHeaders($user))->assertStatus(403);
        $this->getJson('/api/v1/attributes', $this->authHeaders($user))->assertStatus(403);
        $this->getJson('/api/v1/attribute-values', $this->authHeaders($user))->assertStatus(403);

        $this->postJson('/api/v1/categories', [
            'company_id' => $this->company->id,
            'code' => 'NOPE',
            'name' => 'Denied',
        ], $this->authHeaders($user))->assertStatus(403);

        // The granted module still works.
        $this->getJson('/api/v1/brands', $this->authHeaders($user))->assertOk();
    }

    public function test_catalog_is_isolated_between_companies(): void
    {
        $user = $this->authenticatedUser(['categories.view']);
        $other = Category::factory()->for(Company::factory()->create())->create();

        $this->getJson("/api/v1/categories/{$other->id}", $this->authHeaders($user))
            ->assertStatus(403);

        $this->getJson('/api/v1/categories', $this->authHeaders($user))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * @return array{0: int, 1: int} [pcs id, carton id]
     */
    private function createUnit($user, string $code, string $name): int
    {
        return $this->postJson('/api/v1/units', [
            'company_id' => $this->company->id,
            'name' => $name,
            'code' => $code,
            'type' => 'quantity',
        ], $this->authHeaders($user))
            ->assertCreated()
            ->json('data.id');
    }

    private function createCategory($user, array $attributes)
    {
        return $this->postJson('/api/v1/categories', array_merge([
            'company_id' => $this->company->id,
        ], $attributes), $this->authHeaders($user));
    }
}
