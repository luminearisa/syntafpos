<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Services\SettingsService;
use App\Support\BusinessContext;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    public function test_defaults_round_trip_with_correct_types(): void
    {
        $user = $this->authenticatedUser(['settings.view', 'settings.update']);

        /** @var SettingsService $settings */
        $settings = $this->app->make(SettingsService::class);
        $context = $this->app->make(BusinessContext::class);
        $context->setCompany($this->company);

        $settings->set('inventory.enabled', true);
        $settings->set('pos.receipt_width', 58);
        $settings->set('tax.default_rate', 11.5);
        $settings->set('pos.allowed_payments', ['cash', 'qris']);

        $this->assertTrue($settings->get('inventory.enabled'));
        $this->assertSame(58, $settings->get('pos.receipt_width'));
        $this->assertSame(11.5, $settings->get('tax.default_rate'));
        $this->assertSame(['cash', 'qris'], $settings->get('pos.allowed_payments'));
    }

    public function test_can_update_settings_via_api(): void
    {
        $user = $this->authenticatedUser(['settings.view', 'settings.update']);

        $this->putJson('/api/v1/settings', [
            'values' => [
                'pos.receipt_width' => 58,
                'pos.allow_negative_stock' => true,
            ],
        ], $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(58, $this->app->make(SettingsService::class)->get('pos.receipt_width', null, $this->company->id));
    }

    public function test_unauthorized_user_cannot_update_settings(): void
    {
        $user = $this->authenticatedUser(['settings.view']);

        $this->putJson('/api/v1/settings', [
            'values' => ['pos.receipt_width' => 58],
        ], $this->authHeaders($user))
            ->assertForbidden();
    }

    public function test_settings_update_is_audited(): void
    {
        $user = $this->authenticatedUser(['settings.view', 'settings.update']);

        $this->putJson('/api/v1/settings', [
            'values' => ['pos.auto_print' => false],
        ], $this->authHeaders($user))
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'settings.update',
            'entity_type' => 'setting',
        ]);
    }

    public function test_settings_are_company_scoped(): void
    {
        $user = $this->authenticatedUser(['settings.view', 'settings.update']);

        $otherCompany = Company::factory()->create();

        /** @var SettingsService $settings */
        $settings = $this->app->make(SettingsService::class);

        $settings->set('company.name', 'Company A Value', $this->company->id);
        $settings->set('company.name', 'Company B Value', $otherCompany->id);

        $this->assertSame('Company A Value', $settings->get('company.name', null, $this->company->id));
        $this->assertSame('Company B Value', $settings->get('company.name', null, $otherCompany->id));
    }

    public function test_empty_values_validation(): void
    {
        $user = $this->authenticatedUser(['settings.update']);

        $this->putJson('/api/v1/settings', [
            'values' => [],
        ], $this->authHeaders($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors('values');
    }
}
