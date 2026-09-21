<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    public function test_can_create_user_with_business_access(): void
    {
        $admin = $this->authenticatedUser(['users.create', 'users.view']);

        $response = $this->postJson('/api/v1/users', [
            'name' => 'New Cashier',
            'email' => 'cashier2@example.com',
            'password' => 'password123',
            'company_ids' => [$this->company->id],
            'branch_ids' => [$this->branch->id],
            'warehouse_ids' => [$this->warehouse->id],
            'register_ids' => [$this->register->id],
        ], $this->authHeaders($admin))
            ->assertCreated();

        $user = User::where('email', 'cashier2@example.com')->first();

        $this->assertTrue($user->companies->contains($this->company->id));
        $this->assertTrue($user->branches->contains($this->branch->id));
        $this->assertTrue($user->warehouses->contains($this->warehouse->id));
        $this->assertTrue($user->registers->contains($this->register->id));
    }

    public function test_cannot_grant_access_to_foreign_company(): void
    {
        $admin = $this->authenticatedUser(['users.create']);
        $foreignCompany = Company::factory()->create();

        $this->postJson('/api/v1/users', [
            'name' => 'Spy',
            'email' => 'spy@example.com',
            'password' => 'password123',
            'company_ids' => [$foreignCompany->id],
        ], $this->authHeaders($admin))
            ->assertForbidden();
    }

    public function test_cannot_grant_branch_outside_granted_companies(): void
    {
        $admin = $this->authenticatedUser(['users.create']);
        $foreignBranch = Branch::factory()->for(Company::factory()->create())->create();

        $this->postJson('/api/v1/users', [
            'name' => 'Spy',
            'email' => 'spy2@example.com',
            'password' => 'password123',
            'company_ids' => [$this->company->id],
            'branch_ids' => [$foreignBranch->id],
        ], $this->authHeaders($admin))
            ->assertForbidden();
    }

    public function test_can_update_user_roles_and_access(): void
    {
        $admin = $this->authenticatedUser(['users.update', 'users.view']);
        $target = User::factory()->create();

        $role = Role::create([
            'company_id' => $this->company->id,
            'name' => 'cashier_role',
            'display_name' => 'Cashier',
        ]);

        $this->putJson("/api/v1/users/{$target->id}", [
            'name' => 'Renamed',
            'company_ids' => [$this->company->id],
            'role_ids' => [$role->id],
        ], $this->authHeaders($admin))
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed');

        $this->assertTrue($target->fresh()->roles->contains($role->id));
        $this->assertTrue($target->fresh()->companies->contains($this->company->id));
    }

    public function test_user_can_view_own_profile_even_without_permission(): void
    {
        $user = $this->authenticatedUser([]);

        $this->getJson("/api/v1/users/{$user->id}", $this->authHeaders($user))
            ->assertOk();
    }

    public function test_system_admin_cannot_be_deleted(): void
    {
        $admin = $this->authenticatedUser(['users.delete']);
        $system = User::factory()->create(['email' => 'admin@example.com']);

        $this->deleteJson("/api/v1/users/{$system->id}", [], $this->authHeaders($admin))
            ->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $system->id]);
    }

    public function test_can_list_users_filtered_by_company(): void
    {
        $admin = $this->authenticatedUser(['users.view']);
        $other = User::factory()->create();

        $response = $this->getJson('/api/v1/users', $this->authHeaders($admin))
            ->assertOk();

        $emails = collect($response->json('data'))->pluck('email')->all();

        $this->assertContains($admin->email, $emails);
        $this->assertNotContains($other->email, $emails);
    }
}
