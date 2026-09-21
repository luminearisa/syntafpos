<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Warehouse;
use Tests\TestCase;

class BranchWarehouseRegisterTest extends TestCase
{
    public function test_can_create_branch(): void
    {
        $user = $this->authenticatedUser(['branches.create', 'branches.view']);

        $this->postJson('/api/v1/branches', [
            'company_id' => $this->company->id,
            'code' => 'BR-NEW',
            'name' => 'New Outlet',
            'type' => 'outlet',
            'city' => 'Surabaya',
        ], $this->authHeaders($user))
            ->assertCreated()
            ->assertJsonPath('data.code', 'BR-NEW');

        $this->assertDatabaseHas('audit_logs', ['action' => 'branch.create']);
    }

    public function test_branch_code_unique_per_company(): void
    {
        $user = $this->authenticatedUser(['branches.create']);

        $this->postJson('/api/v1/branches', [
            'company_id' => $this->company->id,
            'code' => $this->branch->code,
            'name' => 'Duplicate',
        ], $this->authHeaders($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_can_update_and_delete_branch(): void
    {
        $user = $this->authenticatedUser(['branches.update', 'branches.delete']);
        $branch = Branch::factory()->for($this->company)->create();

        $this->putJson("/api/v1/branches/{$branch->id}", ['name' => 'Updated'], $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated');

        $this->deleteJson("/api/v1/branches/{$branch->id}", [], $this->authHeaders($user))
            ->assertOk();

        $this->assertSoftDeleted('branches', ['id' => $branch->id]);
    }

    public function test_can_create_warehouse_with_branch(): void
    {
        $user = $this->authenticatedUser(['warehouses.create', 'warehouses.view']);

        $this->postJson('/api/v1/warehouses', [
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'code' => 'WH-NEW',
            'name' => 'New Warehouse',
            'type' => 'outlet',
        ], $this->authHeaders($user))
            ->assertCreated()
            ->assertJsonPath('data.code', 'WH-NEW');
    }

    public function test_warehouse_code_unique_per_company(): void
    {
        $user = $this->authenticatedUser(['warehouses.create']);

        $this->postJson('/api/v1/warehouses', [
            'company_id' => $this->company->id,
            'code' => $this->warehouse->code,
            'name' => 'Duplicate',
        ], $this->authHeaders($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_can_create_register(): void
    {
        $user = $this->authenticatedUser(['registers.create', 'registers.view']);

        $this->postJson('/api/v1/registers', [
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'warehouse_id' => $this->warehouse->id,
            'code' => 'REG-NEW',
            'name' => 'Register 02',
        ], $this->authHeaders($user))
            ->assertCreated()
            ->assertJsonPath('data.code', 'REG-NEW');
    }

    public function test_register_hierarchy_is_returned_with_relations(): void
    {
        $user = $this->authenticatedUser(['registers.view']);

        $this->getJson("/api/v1/registers/{$this->register->id}", $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.branch.code', $this->branch->code)
            ->assertJsonPath('data.warehouse.code', $this->warehouse->code);
    }

    public function test_filters_apply(): void
    {
        $user = $this->authenticatedUser(['warehouses.view']);
        $production = Warehouse::factory()->for($this->company)->create(['type' => 'production']);

        $response = $this->getJson('/api/v1/warehouses?type=production', $this->authHeaders($user))
            ->assertOk();

        $codes = collect($response->json('data'))->pluck('code')->all();

        $this->assertContains($production->code, $codes);
        $this->assertNotContains($this->warehouse->code, $codes);
    }
}
