<?php

namespace Tests\Feature;

use Tests\TestCase;

class EnvelopeShapeTest extends TestCase
{
    public function test_list_endpoints_use_the_standard_envelope(): void
    {
        $user = $this->authenticatedUser([
            'companies.view', 'branches.view', 'warehouses.view',
            'registers.view', 'users.view', 'roles.view', 'audit.view',
        ]);

        foreach ([
            '/api/v1/companies',
            '/api/v1/branches',
            '/api/v1/warehouses',
            '/api/v1/registers',
            '/api/v1/users',
            '/api/v1/roles',
            '/api/v1/audit-logs',
        ] as $uri) {
            $response = $this->getJson($uri.'?per_page=2', $this->authHeaders($user));
            $response->assertOk();

            $this->assertTrue(
                $response->json('success'),
                $uri.' missing success flag'
            );
            $this->assertSame('Success', $response->json('message'), $uri.' message');
            $this->assertIsArray($response->json('data'), $uri.' data must be an array');
            $this->assertIsArray($response->json('meta'), $uri.' missing meta');
            $this->assertSame(2, $response->json('meta.per_page'), $uri.' meta.per_page');
            $this->assertArrayHasKey('total', $response->json('meta'), $uri.' meta.total');
        }
    }

    public function test_permissions_endpoint_is_not_paginated(): void
    {
        $user = $this->authenticatedUser(['roles.view']);

        $response = $this->getJson('/api/v1/permissions', $this->authHeaders($user));
        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertIsArray($response->json('data'));

        $groups = collect($response->json('data'))->pluck('group')->unique();
        $this->assertTrue($groups->count() > 1, 'permission grouping was lost');
    }

    public function test_error_responses_share_one_shape(): void
    {
        // Unauthenticated requests must still answer with the standard envelope
        // so the client can parse errors uniformly instead of special-casing 401.
        $unauthenticated = $this->getJson('/api/v1/users');
        $unauthenticated->assertStatus(401);
        $this->assertFalse($unauthenticated->json('success'));
        $this->assertIsString($unauthenticated->json('message'));
        $this->assertIsArray($unauthenticated->json('errors'));

        // Rejections thrown through requirePermission surface as 403.
        $user = $this->authenticatedUser(['companies.view']);

        $forbidden = $this->postJson(
            '/api/v1/companies',
            ['name' => 'Deny Co', 'code' => 'DENY-CO'],
            $this->authHeaders($user)
        );
        $forbidden->assertStatus(403);
        $this->assertFalse($forbidden->json('success'));
        $this->assertIsString($forbidden->json('message'));

        // Validation failures keep the same keys at 422.
        $authorised = $this->authenticatedUser(['companies.create']);
        $invalid = $this->postJson('/api/v1/companies', [], $this->authHeaders($authorised));
        $invalid->assertStatus(422);
        $this->assertFalse($invalid->json('success'));
        $this->assertIsArray($invalid->json('errors'));

        // Missing resources answer with the envelope rather than a bare error page.
        $missing = $this->getJson('/api/v1/companies/999999', $this->authHeaders($authorised));
        $missing->assertStatus(404);
        $this->assertFalse($missing->json('success'));
    }
}
