<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Warehouse;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    public function test_user_without_permission_is_denied(): void
    {
        $user = $this->authenticatedUser([]);

        $this->getJson('/api/v1/companies', $this->authHeaders($user))
            ->assertForbidden();
    }

    public function test_user_with_permission_is_allowed(): void
    {
        $user = $this->authenticatedUser(['companies.view']);

        $this->getJson('/api/v1/companies', $this->authHeaders($user))
            ->assertOk();
    }

    public function test_user_cannot_see_companies_they_do_not_belong_to(): void
    {
        $user = $this->authenticatedUser(['companies.view']);
        $otherCompany = Company::factory()->create();

        $this->getJson('/api/v1/companies', $this->authHeaders($user))
            ->assertOk()
            ->assertJsonMissing(['code' => $otherCompany->code]);
    }

    public function test_user_cannot_create_branch_in_foreign_company(): void
    {
        $user = $this->authenticatedUser(['branches.create', 'branches.view']);
        $foreignCompany = Company::factory()->create();

        $this->postJson('/api/v1/branches', [
            'company_id' => $foreignCompany->id,
            'code' => 'X-01',
            'name' => 'Foreign Branch',
        ], $this->authHeaders($user))
            ->assertForbidden();
    }

    public function test_warehouse_cannot_reference_foreign_branch(): void
    {
        $user = $this->authenticatedUser(['warehouses.create']);
        $foreignBranch = Branch::factory()->for(Company::factory()->create())->create();

        $this->postJson('/api/v1/warehouses', [
            'company_id' => $this->company->id,
            'branch_id' => $foreignBranch->id,
            'code' => 'WH-X',
            'name' => 'Mixed Warehouse',
        ], $this->authHeaders($user))
            ->assertForbidden();
    }

    public function test_register_cannot_reference_foreign_warehouse(): void
    {
        $user = $this->authenticatedUser(['registers.create']);
        $foreignWarehouse = Warehouse::factory()->for(Company::factory()->create())->create();

        $this->postJson('/api/v1/registers', [
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'warehouse_id' => $foreignWarehouse->id,
            'code' => 'REG-X',
            'name' => 'Mixed Register',
        ], $this->authHeaders($user))
            ->assertForbidden();
    }

    public function test_user_without_direct_branch_grant_still_sees_company_branches(): void
    {
        // Company-level access implies visibility of that company's branches.
        $user = $this->authenticatedUser(['branches.view']);
        $otherBranch = Branch::factory()->for($this->company)->create();

        $response = $this->getJson('/api/v1/branches', $this->authHeaders($user))
            ->assertOk();

        $visible = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($this->branch->id, $visible);
        $this->assertContains($otherBranch->id, $visible);
    }

    public function test_user_cannot_see_branches_outside_their_companies(): void
    {
        $user = $this->authenticatedUser(['branches.view']);
        $foreignBranch = Branch::factory()->for(Company::factory()->create())->create();

        $response = $this->getJson('/api/v1/branches', $this->authHeaders($user))
            ->assertOk();

        $visible = collect($response->json('data'))->pluck('id')->all();

        $this->assertNotContains($foreignBranch->id, $visible);
    }

    public function test_role_without_delete_permission_cannot_delete(): void
    {
        $user = $this->authenticatedUser(['branches.view']);
        $branch = Branch::factory()->for($this->company)->create();

        $this->deleteJson("/api/v1/branches/{$branch->id}", [], $this->authHeaders($user))
            ->assertForbidden();

        $this->assertDatabaseHas('branches', ['id' => $branch->id]);
    }
}
