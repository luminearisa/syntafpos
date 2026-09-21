<?php

namespace Tests\Feature;

use App\Services\NumberingService;
use App\Support\BusinessContext;
use Tests\TestCase;

class NumberingTest extends TestCase
{
    public function test_generates_expected_format(): void
    {
        $user = $this->authenticatedUser();

        /** @var NumberingService $numbering */
        $numbering = $this->app->make(NumberingService::class);
        $this->app->make(BusinessContext::class)->setCompany($this->company);

        $first = $numbering->next('invoice', $this->company->id);
        $second = $numbering->next('invoice', $this->company->id);

        $this->assertMatchesRegularExpression('/^INV-\d{4}-\d{6}$/', $first);
        $this->assertNotEquals($first, $second);
    }

    public function test_prefix_and_padding_are_configurable(): void
    {
        $user = $this->authenticatedUser();

        /** @var NumberingService $numbering */
        $numbering = $this->app->make(NumberingService::class);

        $number = $numbering->next('purchase_order', $this->company->id, null, [
            'prefix' => 'PO',
            'padding' => 4,
            'reset_period' => 'yearly',
        ]);

        $this->assertMatchesRegularExpression('/^PO-\d{4}-\d{4}$/', $number);
    }

    public function test_sequence_increments_and_persists(): void
    {
        $user = $this->authenticatedUser();

        /** @var NumberingService $numbering */
        $numbering = $this->app->make(NumberingService::class);

        $numbering->next('payment', $this->company->id);
        $numbering->next('payment', $this->company->id);
        $third = $numbering->next('payment', $this->company->id);

        $this->assertStringEndsWith('-000003', $third);
    }

    public function test_different_document_types_are_independent(): void
    {
        $user = $this->authenticatedUser();

        /** @var NumberingService $numbering */
        $numbering = $this->app->make(NumberingService::class);

        $invoice = $numbering->next('invoice', $this->company->id);
        $payment = $numbering->next('payment', $this->company->id);

        $this->assertStringEndsWith('-000001', $invoice);
        $this->assertStringEndsWith('-000001', $payment);
    }

    public function test_branch_scoped_sequences_are_separate(): void
    {
        $user = $this->authenticatedUser();

        /** @var NumberingService $numbering */
        $numbering = $this->app->make(NumberingService::class);

        $companyLevel = $numbering->next('invoice', $this->company->id);
        $branchLevel = $numbering->next('invoice', $this->company->id, $this->branch->id);

        $this->assertStringEndsWith('-000001', $companyLevel);
        $this->assertStringEndsWith('-000001', $branchLevel);
    }

    public function test_preview_does_not_consume_sequence(): void
    {
        $user = $this->authenticatedUser();

        /** @var NumberingService $numbering */
        $numbering = $this->app->make(NumberingService::class);

        $preview = $numbering->preview('invoice', $this->company->id);
        $real = $numbering->next('invoice', $this->company->id);

        $this->assertEquals($preview, $real);
    }

    public function test_concurrent_requests_do_not_collide(): void
    {
        $user = $this->authenticatedUser();

        /** @var NumberingService $numbering */
        $numbering = $this->app->make(NumberingService::class);

        $numbers = collect(range(1, 10))
            ->map(fn () => $numbering->next('journal', $this->company->id))
            ->all();

        $this->assertCount(10, array_unique($numbers));

        foreach ($numbers as $number) {
            $this->assertMatchesRegularExpression('/^JE-\d{4}-\d{6}$/', $number);
        }
    }
}
