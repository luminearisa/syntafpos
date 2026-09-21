<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'cashier@example.com',
            'password' => 'password',
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'cashier@example.com',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['token', 'user' => ['id', 'name', 'email']],
            ]);

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_user_cannot_login_with_invalid_password(): void
    {
        User::factory()->create(['email' => 'cashier@example.com']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'cashier@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_suspended_user_cannot_login(): void
    {
        User::factory()->create([
            'email' => 'suspended@example.com',
            'status' => 'suspended',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'suspended@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_login_records_audit_log(): void
    {
        $user = $this->authenticatedUser();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'login',
            'entity_type' => 'user',
            'entity_id' => $user->id,
            'company_id' => $this->company->id,
        ]);
    }

    public function test_authenticated_user_can_fetch_profile(): void
    {
        $user = $this->authenticatedUser();

        $this->getJson('/api/v1/auth/me', $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.user.email', $user->email);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    public function test_user_can_logout(): void
    {
        $user = $this->authenticatedUser();
        $headers = $this->authHeaders($user);

        $this->postJson('/api/v1/auth/logout', [], $headers)
            ->assertOk()
            ->assertJsonPath('success', true);

        // The presented token row is destroyed.
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
        ]);
    }

    public function test_user_can_change_password(): void
    {
        $user = $this->authenticatedUser();

        $this->putJson('/api/v1/auth/password', [
            'current_password' => 'password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ], $this->authHeaders($user))
            ->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'new-password-123',
        ])->assertOk();
    }

    public function test_change_password_rejects_wrong_current_password(): void
    {
        $user = $this->authenticatedUser();

        $this->putJson('/api/v1/auth/password', [
            'current_password' => 'totally-wrong',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ], $this->authHeaders($user))
            ->assertStatus(422);
    }
}
