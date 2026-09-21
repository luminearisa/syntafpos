<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Register;
use App\Models\Warehouse;
use Tests\TestCase;

class CompanyTest extends TestCase
{
    public function test_can_list_companies(): void
    {
        $user = $this->authenticatedUser(['companies.view']);
        $other = Company::factory()->create();

        $response = $this->getJson('/api/v1/companies', $this->authHeaders($user))
            ->assertOk();

        $codes = collect($response->json('data'))->pluck('code')->all();

        $this->assertContains($this->company->code, $codes);
        $this->assertNotContains($other->code, $codes);
    }

    public function test_can_create_company(): void
    {
        $user = $this->authenticatedUser(['companies.create', 'companies.view']);

        $response = $this->postJson('/api/v1/companies', [
            'name' => 'New Business',
            'code' => 'NEWBIZ',
            'city' => 'Bandung',
        ], $this->authHeaders($user))
            ->assertCreated()
            ->assertJsonPath('data.code', 'NEWBIZ');

        $company = Company::where('code', 'NEWBIZ')->first();
        $this->assertNotNull($company);
        $this->assertTrue($user->fresh()->companies->contains($company->id));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'company.create',
            'entity_type' => 'company',
            'entity_id' => $company->id,
        ]);
    }

    public function test_company_code_must_be_unique(): void
    {
        $user = $this->authenticatedUser(['companies.create']);

        $this->postJson('/api/v1/companies', [
            'name' => 'Duplicate',
            'code' => $this->company->code,
        ], $this->authHeaders($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_can_update_company(): void
    {
        $user = $this->authenticatedUser(['companies.update']);

        $this->putJson("/api/v1/companies/{$this->company->id}", [
            'name' => 'Renamed Business',
        ], $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed Business');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'company.update',
            'entity_type' => 'company',
            'entity_id' => $this->company->id,
        ]);
    }

    public function test_cannot_delete_company_with_branches(): void
    {
        $user = $this->authenticatedUser(['companies.delete']);

        $this->deleteJson("/api/v1/companies/{$this->company->id}", [], $this->authHeaders($user))
            ->assertStatus(422);

        $this->assertDatabaseHas('companies', ['id' => $this->company->id]);
    }

    public function test_can_delete_empty_company(): void
    {
        $user = $this->authenticatedUser(['companies.delete']);

        // Detach the seeded hierarchy so the company has no branches left.
        Register::query()->where('company_id', $this->company->id)->delete();
        Warehouse::query()->where('company_id', $this->company->id)->delete();
        Branch::query()->where('company_id', $this->company->id)->delete();

        $this->deleteJson("/api/v1/companies/{$this->company->id}", [], $this->authHeaders($user))
            ->assertOk();

        $this->assertSoftDeleted('companies', ['id' => $this->company->id]);
    }

    public function test_can_filter_and_search_companies(): void
    {
        $user = $this->authenticatedUser(['companies.view']);

        $response = $this->getJson('/api/v1/companies?search='.$this->company->code, $this->authHeaders($user))
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
    }
}
