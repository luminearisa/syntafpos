<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    public function test_login_and_logout_are_logged(): void
    {
        $user = $this->authenticatedUser();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $loginLog = AuditLog::query()
            ->where('action', 'login')
            ->latest()
            ->first();

        $this->assertNotNull($loginLog);
        $this->assertSame($user->id, $loginLog->user_id);
        $this->assertSame($this->company->id, $loginLog->company_id);
    }

    public function test_create_and_update_capture_old_and_new_values(): void
    {
        $user = $this->authenticatedUser(['branches.create', 'branches.update']);

        $create = $this->postJson('/api/v1/branches', [
            'company_id' => $this->company->id,
            'code' => 'AU-01',
            'name' => 'Audit Branch',
        ], $this->authHeaders($user))
            ->assertCreated();

        $branchId = $create->json('data.id');

        $this->putJson("/api/v1/branches/{$branchId}", [
            'name' => 'Renamed Branch',
        ], $this->authHeaders($user))
            ->assertOk();

        $updateLog = AuditLog::query()
            ->where('action', 'branch.update')
            ->where('entity_id', $branchId)
            ->latest()
            ->first();

        $this->assertNotNull($updateLog);
        $this->assertSame(['name' => 'Audit Branch'], $updateLog->old_values);
        $this->assertSame(['name' => 'Renamed Branch'], $updateLog->new_values);
    }

    public function test_audit_records_request_metadata(): void
    {
        $user = $this->authenticatedUser(['companies.update']);

        $this->putJson("/api/v1/companies/{$this->company->id}", [
            'name' => 'Meta Test',
        ], $this->authHeaders($user))
            ->assertOk();

        $log = AuditLog::query()->where('action', 'company.update')->first();

        $this->assertNotNull($log);
        $this->assertSame($user->id, $log->user_id);
        $this->assertNotNull($log->ip_address);
        $this->assertNotNull($log->user_agent);
    }

    public function test_user_with_audit_permission_can_view_logs(): void
    {
        $user = $this->authenticatedUser(['audit.view']);

        $this->getJson('/api/v1/audit-logs', $this->authHeaders($user))
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_user_without_audit_permission_cannot_view_logs(): void
    {
        $user = $this->authenticatedUser(['companies.view']);

        $this->getJson('/api/v1/audit-logs', $this->authHeaders($user))
            ->assertForbidden();
    }

    public function test_audit_logs_are_scoped_to_users_companies(): void
    {
        $user = $this->authenticatedUser(['audit.view']);
        $otherCompany = Company::factory()->create();
        $otherUser = User::factory()->create();
        $otherUser->companies()->attach($otherCompany->id);

        AuditLog::create([
            'company_id' => $otherCompany->id,
            'user_id' => $otherUser->id,
            'action' => 'login',
        ]);

        $response = $this->getJson('/api/v1/audit-logs', $this->authHeaders($user))
            ->assertOk();

        $companyIds = collect($response->json('data'))->pluck('company_id')->all();

        $this->assertNotContains($otherCompany->id, $companyIds);
    }
}
