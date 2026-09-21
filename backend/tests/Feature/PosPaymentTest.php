<?php

namespace Tests\Feature;

use App\Contracts\Payments\PaymentMethodInterface;
use App\Contracts\Payments\PaymentProviderInterface;
use App\Contracts\Payments\PaymentProviderRegistry;
use App\Enums\PaymentChannel;
use App\Enums\PaymentStatus;
use App\Enums\SaleStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Register;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Unit;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The payment engine: what a tender is allowed to be, and what it does to a sale.
 *
 * Every case here is one line of the Subphase 3.3 work order — cash, exact,
 * change, multiple payment, failed, duplicate, partial, overpayment, cancelled
 * sale — plus the two the order implies but does not list: a payment whose method
 * names a gateway this server does not have, and the derived money on a sale after
 * a tender is withdrawn.
 *
 * The organising question in each test is not "did a row appear" but "is the money
 * on the sale still true". paid_total, change_due, balance_due and the sale's own
 * status are sums of the payment rows, so a test that only checked the row would
 * pass while the takings were wrong.
 */
class PosPaymentTest extends TestCase
{
    protected User $cashier;

    protected Unit $unit;

    protected Product $coffee;

    protected ?TestResponse $last = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashier = $this->authenticatedUser([
            'pos.view', 'pos.transact', 'pos.hold',
            'sales.view', 'sales.create', 'sales.complete', 'sales.cancel',
            'payment_methods.view',
        ]);

        $this->unit = Unit::factory()->for($this->company)->create(['code' => 'PCS']);
        $this->coffee = Product::factory()->for($this->company)->create([
            'sku' => 'COF-P33',
            'name' => 'Coffee House Blend',
            'default_unit_id' => $this->unit->id,
            'is_sellable' => true,
            'is_active' => true,
            'track_inventory' => false,
            // One flat price, no tax: the money arithmetic below is about
            // tenders, and an 11% rate would only add digits to read past.
            'selling_price' => '25000.0000',
        ]);
    }

    //
    // Fixtures
    //

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function method(array $attributes = []): PaymentMethod
    {
        return PaymentMethod::factory()->for($this->company)->create($attributes);
    }

    /** A method that only a gateway can confirm — 3.3 installs none. */
    protected function capturedMethod(string $provider = 'midtrans'): PaymentMethod
    {
        return PaymentMethod::factory()->for($this->company)->pendingCapture($provider)->create();
    }

    /**
     * A cart holding $quantity units of the test product.
     *
     * @return array<string, mixed> the server's own cart, totals included
     */
    protected function cart(int $quantity = 4, ?Customer $customer = null): array
    {
        $cartId = (int) $this->getJson('/api/v1/pos/cart', $this->headers())->assertOk()->json('data.id');

        if ($customer) {
            $this->putJson("/api/v1/pos/cart/{$cartId}", ['customer_id' => $customer->id], $this->headers())
                ->assertOk();
        }

        return $this->postJson("/api/v1/pos/cart/{$cartId}/items", [
            'product_id' => $this->coffee->id,
            'quantity' => (string) $quantity,
        ], $this->headers())->assertCreated()->json('data.cart');
    }

    /**
     * @param  array<int, array<string, mixed>>  $payments
     * @param  array<string, mixed>  $extra
     */
    protected function checkout(array $payments, array $extra = [], ?User $user = null): TestResponse
    {
        return $this->last = $this->postJson('/api/v1/sales', [
            'cart_id' => $extra['cart_id'] ?? $this->cart()['id'],
            'payments' => $payments,
        ] + array_diff_key($extra, ['cart_id' => true]), $this->headers($user));
    }

    /**
     * @param  array<int, array<string, mixed>>  $payments
     */
    protected function complete(int $saleId, array $payments): TestResponse
    {
        return $this->last = $this->postJson("/api/v1/sales/{$saleId}/complete", [
            'payments' => $payments,
        ], $this->headers());
    }

    /**
     * @param  array<string, string>  $context
     */
    protected function headers(?User $user = null, array $context = []): array
    {
        $user ??= $this->cashier;

        return $this->authHeaders($user, [
            'company_id' => $context['company_id'] ?? $user->companies()->first()?->id,
            'branch_id' => $context['branch_id'] ?? $user->branches()->first()?->id,
            'warehouse_id' => $context['warehouse_id'] ?? $user->warehouses()->first()?->id,
            'register_id' => $context['register_id'] ?? $user->registers()->first()?->id,
        ]);
    }

    protected function errorMessage(string $key): string
    {
        $errors = (array) $this->last?->json('errors');

        return implode(' | ', (array) ($errors[$key] ?? $this->last?->json("errors.{$key}")));
    }

    protected function service(): PaymentService
    {
        return $this->app->make(PaymentService::class);
    }

    //
    // The work order's list, one test per line.
    //

    public function test_cash_is_recorded_as_its_own_channel_and_names_its_method(): void
    {
        $this->checkout([['channel' => 'cash', 'amount' => '100000.0000', 'tendered' => '100000.0000']])
            ->assertCreated()
            ->assertJsonPath('data.status', SaleStatus::Completed->value)
            ->assertJsonPath('data.payments.0.channel', 'cash')
            ->assertJsonPath('data.payments.0.channel_label', 'Cash')
            ->assertJsonPath('data.payments.0.status', PaymentStatus::Paid->value)
            // Cash is the one tender with a drawer behind it, so the amount handed
            // over is stored rather than inferred.
            ->assertJsonPath('data.payments.0.tendered', '100000.0000')
            ->assertJsonPath('data.payments.0.change', '0.0000');

        $this->assertNotNull(SalePayment::sole()->paid_at, 'a settled tender records when it settled');

        $this->assertDatabaseHas('sale_payments', [
            'channel' => 'cash',
            'method_name' => 'Cash',
            'currency' => 'IDR',
            'status' => 'paid',
        ]);
    }

    public function test_an_exact_payment_settles_the_sale_without_change(): void
    {
        $cart = $this->cart();

        $sale = $this->checkout([['channel' => 'cash', 'amount' => $cart['grand_total']]], ['cart_id' => $cart['id']])
            ->assertCreated()
            ->json('data');

        $this->assertSame($cart['grand_total'], $sale['grand_total']);
        $this->assertSame($cart['grand_total'], $sale['paid_total']);
        $this->assertSame('0.0000', $sale['balance_due']);
        $this->assertSame('0.0000', $sale['change_due']);
        $this->assertSame('Paid', $sale['payment_status']);
        // tendered defaults to the amount: a cashier who types nothing extra did
        // hand over exactly the sum, and that is what the drawer counts.
        $this->assertSame($sale['grand_total'], $sale['payments'][0]['tendered']);
    }

    public function test_change_comes_back_out_of_the_drawer_and_is_never_revenue(): void
    {
        // 4 x 25.000 = 100.000 due; the customer hands over a 150.000 note.
        $sale = $this->checkout([['channel' => 'cash', 'amount' => '100000.0000', 'tendered' => '150000.0000']])
            ->assertCreated()
            ->json('data');

        $this->assertSame('100000.0000', $sale['grand_total'], 'the ticket is still worth what it was worth');
        $this->assertSame('100000.0000', $sale['paid_total'], 'change must not inflate what the shop earned');
        $this->assertSame('50000.0000', $sale['payments'][0]['change']);
        $this->assertSame('50000.0000', $sale['change_due']);
        $this->assertSame('0.0000', $sale['balance_due']);
        $this->assertSame(SaleStatus::Completed->value, $sale['status']);
        $this->assertSame('Paid', $sale['payment_status']);
    }

    public function test_a_sale_can_be_settled_by_several_tenders_summing_to_the_balance(): void
    {
        // The work order's example: 500.000 = 200.000 cash + 300.000 QRIS.
        $cart = $this->cart(20);

        $this->assertSame('500000.0000', $cart['grand_total']);

        $sale = $this->checkout([
            ['channel' => 'cash', 'amount' => '200000.0000', 'tendered' => '200000.0000'],
            ['channel' => 'qris', 'amount' => '300000.0000'],
        ], ['cart_id' => $cart['id']])->assertCreated()->json('data');

        $this->assertCount(2, $sale['payments']);
        $this->assertSame('200000.0000', $sale['payments'][0]['amount']);
        $this->assertSame('300000.0000', $sale['payments'][1]['amount']);
        $this->assertSame('500000.0000', $sale['paid_total']);
        $this->assertSame('0.0000', $sale['balance_due']);
        $this->assertSame(SaleStatus::Completed->value, $sale['status']);

        // Every tender carries its own number from the sequence engine, so a
        // reconciliation can name the row it is querying.
        $this->assertSame('PAY-2026-000001', $sale['payments'][0]['number']);
        $this->assertSame('PAY-2026-000002', $sale['payments'][1]['number']);
    }

    public function test_a_payment_only_as_far_as_it_goes_leaves_a_partially_paid_ticket(): void
    {
        $cart = $this->cart();

        $sale = $this->checkout([['channel' => 'cash', 'amount' => '40000.0000']], ['cart_id' => $cart['id']])
            ->assertCreated()
            ->json('data');

        $this->assertSame(SaleStatus::PartiallyPaid->value, $sale['status']);
        $this->assertSame('40000.0000', $sale['paid_total']);
        $this->assertSame('60000.0000', $sale['balance_due']);
        $this->assertSame('Partially paid', $sale['payment_status']);
        // The whole point of the split: stock has not moved on an unpaid ticket.
        $this->assertNull($sale['stock_posted_at']);
        $this->assertSame(1, SalePayment::query()->where('sale_id', $sale['id'])->count());
    }

    public function test_the_second_half_of_a_partially_paid_ticket_can_be_taken_later(): void
    {
        $cart = $this->cart();
        $sale = $this->checkout([['channel' => 'cash', 'amount' => '40000.0000']], ['cart_id' => $cart['id']])
            ->assertCreated()->json('data');

        $settled = $this->complete((int) $sale['id'], [
            ['channel' => 'bank_transfer', 'amount' => '60000.0000', 'reference' => 'TRF-889'],
        ])->assertOk()->json('data');

        $this->assertCount(2, $settled['payments']);
        $this->assertSame('100000.0000', $settled['paid_total']);
        $this->assertSame('0.0000', $settled['balance_due']);
        $this->assertSame(SaleStatus::Completed->value, $settled['status']);
        $this->assertSame('TRF-889', $settled['payments'][1]['reference']);
        $this->assertSame('Bank transfer', $settled['payments'][1]['method_name']);
    }

    public function test_a_tender_larger_than_what_is_outstanding_is_refused(): void
    {
        // 100.000 due, 120.000 offered: not generosity, a mistake or a deposit.
        $this->checkout([['channel' => 'cash', 'amount' => '120000.0000', 'tendered' => '120000.0000']])
            ->assertStatus(422);

        $this->assertStringContainsString(
            'larger than the remaining balance of Rp 100.000',
            $this->errorMessage('payments.amount')
        );

        // Refused means nothing happened: no ticket, no tender.
        $this->assertSame(0, Sale::count());
        $this->assertSame(0, SalePayment::count());
    }

    public function test_a_tender_beyond_the_remaining_balance_is_refused_after_a_partial(): void
    {
        $cart = $this->cart();
        $sale = $this->checkout([['channel' => 'cash', 'amount' => '70000.0000']], ['cart_id' => $cart['id']])
            ->assertCreated()->json('data');

        $this->complete((int) $sale['id'], [['channel' => 'qris', 'amount' => '40000.0000']])->assertStatus(422);
        $this->assertStringContainsString('remaining balance of Rp 30.000', $this->errorMessage('payments.amount'));

        // The failed tender left no row, and the sale's money still reads 70.000.
        $this->assertSame(1, SalePayment::query()->where('sale_id', $sale['id'])->count());
        $this->assertSame('70000.0000', Sale::find($sale['id'])->paid_total);
    }

    public function test_a_sale_with_nothing_owing_cannot_be_paid_again(): void
    {
        $cart = $this->cart();
        $sale = $this->checkout([['channel' => 'cash', 'amount' => $cart['grand_total']]], ['cart_id' => $cart['id']])
            ->assertCreated()->json('data');

        $this->complete((int) $sale['id'], [['channel' => 'cash', 'amount' => '10000.0000']])->assertStatus(422);
        $this->assertStringContainsString('is already completed', $this->errorMessage('sale'));
    }

    public function test_a_ticket_that_owes_nothing_refuses_a_further_tender(): void
    {
        // A ticket whose money is all in but which has not been closed — the state
        // a confirmed capture leaves behind — has no balance for a tender to land on.
        $cart = $this->cart();
        $sale = $this->checkout([], ['cart_id' => $cart['id']])->assertCreated()->json('data');

        $pending = $this->service()->takeByChannel(
            Sale::find($sale['id']),
            $this->cashier,
            ['channel' => 'qris', 'amount' => '100000.0000'],
            allowPending: true
        );
        $this->service()->confirm($pending);

        try {
            $this->service()->takeByChannel(Sale::find($sale['id']), $this->cashier, [
                'channel' => 'cash', 'amount' => '10000.0000',
            ]);
            $this->fail('A settled ticket must not take money it does not owe.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('has nothing outstanding', $exception->errors()['payments.amount'][0]);
        }
    }

    public function test_a_negative_or_zero_tender_is_refused_as_a_data_error(): void
    {
        // -5.000 is a refund trying to enter through the till, and 0 is a ticket
        // that looks paid while settling nothing.
        foreach (['-5000.0000', '0', '0.0000'] as $amount) {
            $this->checkout([['channel' => 'cash', 'amount' => $amount]])->assertStatus(422);
        }

        $this->assertSame(0, Sale::count());
        $this->assertSame(0, SalePayment::count());
    }

    public function test_repeating_the_same_tender_on_one_sale_is_refused(): void
    {
        $cart = $this->cart();
        $this->checkout([['channel' => 'cash', 'amount' => '50000.0000']], ['cart_id' => $cart['id']])
            ->assertCreated();

        // Same sale, same channel, same figure: a double tap, and the till has no
        // way to know otherwise, so it asks the cashier to say something different.
        $sale = Sale::sole();

        $this->complete((int) $sale->id, [['channel' => 'cash', 'amount' => '50000.0000']])->assertStatus(422);
        $this->assertStringContainsString('already has an identical payment', $this->errorMessage('payments'));

        $this->assertSame(1, SalePayment::query()->where('sale_id', $sale->id)->count());
        $this->assertSame('50000.0000', $sale->fresh()->paid_total);
    }

    public function test_the_same_tender_twice_on_two_different_sales_is_allowed(): void
    {
        // The guard is per sale. Two customers buying the same coffee in two
        // 25.000 notes is an ordinary afternoon.
        $first = $this->checkout([['channel' => 'cash', 'amount' => '100000.0000']])
            ->assertCreated()->json('data');
        $second = $this->checkout([['channel' => 'cash', 'amount' => '100000.0000']])
            ->assertCreated()->json('data');

        $this->assertNotSame($first['id'], $second['id']);
        $this->assertSame(2, SalePayment::query()->where('amount', '100000.0000')->count());
    }

    public function test_one_bank_reference_cannot_be_recorded_twice_on_a_sale(): void
    {
        $cart = $this->cart();
        $sale = $this->checkout([
            ['channel' => 'bank_transfer', 'amount' => '40000.0000', 'reference' => 'TRF-777'],
        ], ['cart_id' => $cart['id']])->assertCreated()->json('data');

        // A different amount, the same slip: the bank will not file one transfer
        // against two lines of the same ticket.
        $this->complete((int) $sale['id'], [
            ['channel' => 'qris', 'amount' => '30000.0000', 'reference' => 'TRF-777'],
        ])->assertStatus(422);

        $this->assertStringContainsString('reference TRF-777 is already recorded', $this->errorMessage('payments.reference'));
        $this->assertSame(1, SalePayment::query()->where('sale_id', $sale['id'])->count());
    }

    public function test_a_tender_on_a_cancelled_sale_is_refused(): void
    {
        $cart = $this->cart();
        $sale = $this->checkout([['channel' => 'cash', 'amount' => '40000.0000']], ['cart_id' => $cart['id']])
            ->assertCreated()->json('data');

        $this->postJson("/api/v1/sales/{$sale['id']}/cancel", ['reason' => 'Wrong order'], $this->headers())
            ->assertOk();

        $this->complete((int) $sale['id'], [['channel' => 'cash', 'amount' => '60000.0000']])->assertStatus(422);
        $this->assertStringContainsString('is cancelled', $this->errorMessage('sale'));

        // Nothing new was recorded and the withdrawn ticket stayed withdrawn.
        $this->assertSame(1, SalePayment::query()->where('sale_id', $sale['id'])->count());
        $this->assertSame(SaleStatus::Cancelled->value, Sale::find($sale['id'])->status->value);
        $this->assertSame('0.0000', Sale::find($sale['id'])->paid_total);
    }

    public function test_cancelling_a_sale_withdraws_its_settled_tenders_and_reopens_the_money(): void
    {
        $cart = $this->cart();
        $sale = $this->checkout([
            ['channel' => 'cash', 'amount' => '20000.0000', 'tendered' => '30000.0000'],
            ['channel' => 'qris', 'amount' => '80000.0000'],
        ], ['cart_id' => $cart['id']])->assertCreated()->json('data');

        $this->assertSame('100000.0000', $sale['paid_total']);
        $this->assertSame('10000.0000', $sale['change_due']);

        $cancelled = $this->postJson("/api/v1/sales/{$sale['id']}/cancel", [], $this->headers())
            ->assertOk()->json('data');

        // Both tenders say cancelled, and the sale's money is the sum of them —
        // which is zero, because nothing counts any more.
        $this->assertSame(['cancelled', 'cancelled'], array_column($cancelled['payments'], 'status'));
        $this->assertSame('0.0000', $cancelled['paid_total']);
        $this->assertSame('0.0000', $cancelled['change_due'], 'change is not owed on a ticket that was withdrawn');
        $this->assertSame($cancelled['grand_total'], $cancelled['balance_due']);
        $this->assertSame('Cancelled', $cancelled['payment_status']);
        // The rows are still there: a payment is never deleted, only retired.
        $this->assertSame(2, SalePayment::query()->where('sale_id', $sale['id'])->count());
    }

    public function test_a_failed_tender_settles_nothing_and_keeps_its_row(): void
    {
        $cart = $this->cart();
        $sale = $this->checkout([['channel' => 'cash', 'amount' => '50000.0000']], ['cart_id' => $cart['id']])
            ->assertCreated()->json('data');

        $pending = $this->service()->takeByChannel(
            Sale::find($sale['id']),
            $this->cashier,
            ['channel' => 'credit_card', 'amount' => '50000.0000'],
            allowPending: true
        );

        $this->assertSame(PaymentStatus::Pending, $pending->status);
        $this->assertSame('50000.0000', $pending->amount);
        $this->assertNull($pending->paid_at);

        $afterFailure = $this->service()->fail($pending, 'Card declined');

        $this->assertSame(PaymentStatus::Failed, $afterFailure->status);
        $this->assertSame('Card declined', $afterFailure->metadata['failure_reason']);
        // The ticket is no more paid than it was: 50.000 of two tenders, one dead.
        $fresh = Sale::find($sale['id']);
        $this->assertSame('50000.0000', $fresh->paid_total);
        $this->assertSame(SaleStatus::PartiallyPaid->value, $fresh->status->value);
        $this->assertSame('50000.0000', bcsub($fresh->grand_total, $fresh->paid_total, 4));
    }

    public function test_a_pending_tender_can_be_confirmed_afterwards(): void
    {
        $cart = $this->cart();
        $sale = $this->checkout([], ['cart_id' => $cart['id']])->assertCreated()->json('data');

        $pending = $this->service()->takeByChannel(
            Sale::find($sale['id']),
            $this->cashier,
            ['channel' => 'qris', 'amount' => '100000.0000'],
            allowPending: true
        );

        $unpaid = Sale::find($sale['id']);
        $this->assertSame('0.0000', $unpaid->paid_total, 'a promise is not a payment');
        $this->assertSame(SaleStatus::Draft->value, $unpaid->status->value);

        $confirmed = $this->service()->confirm($pending, 'QRIS-2291');

        $this->assertSame(PaymentStatus::Paid, $confirmed->status);
        $this->assertSame('QRIS-2291', $confirmed->reference);
        $this->assertNotNull($confirmed->paid_at);

        $now = Sale::find($sale['id']);
        $this->assertSame('100000.0000', $now->paid_total);
        $this->assertSame('0.0000', bcsub($now->grand_total, $now->paid_total, 4));
        $this->assertSame(SaleStatus::Paid->value, $now->status->value);
    }

    public function test_a_dead_tender_cannot_be_revived(): void
    {
        $cart = $this->cart();
        $sale = $this->checkout([], ['cart_id' => $cart['id']])->assertCreated()->json('data');

        $payment = $this->service()->takeByChannel(
            Sale::find($sale['id']),
            $this->cashier,
            ['channel' => 'e_wallet', 'amount' => '100000.0000'],
            allowPending: true
        );

        $this->service()->fail($payment, 'Customer walked away');

        // A callback arriving late must not resurrect a tender the drawer wrote off.
        $this->expectException(ValidationException::class);
        $this->service()->confirm($payment->fresh());
    }

    /**
     * The engine's own cancelled-sale guard, one layer under the endpoint.
     *
     * complete() refuses a cancelled ticket before it reaches the money, so the
     * HTTP test above proves the door is shut without proving *which* door. Called
     * directly, the payment engine has to say it for itself — which is what 3.8's
     * capture jobs and any later caller without a sale controller will rely on.
     */
    public function test_the_engine_itself_refuses_a_tender_on_a_cancelled_sale(): void
    {
        $cart = $this->cart();
        $sale = $this->checkout([['channel' => 'cash', 'amount' => '40000.0000']], ['cart_id' => $cart['id']])
            ->assertCreated()->json('data');

        $this->postJson("/api/v1/sales/{$sale['id']}/cancel", [], $this->headers())->assertOk();

        try {
            $this->service()->takeByChannel(Sale::find($sale['id']), $this->cashier, [
                'channel' => 'qris', 'amount' => '60000.0000',
            ]);
            $this->fail('The payment engine accepted a tender on a cancelled sale.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'no payment can be taken against it',
                $exception->errors()['payments'][0]
            );
        }

        $this->assertSame(1, SalePayment::query()->where('sale_id', $sale['id'])->count());
    }

    public function test_a_paid_tender_cannot_be_cancelled_on_its_own(): void
    {
        $cart = $this->cart();
        $sale = $this->checkout([['channel' => 'cash', 'amount' => $cart['grand_total']]], ['cart_id' => $cart['id']])
            ->assertCreated()->json('data');

        try {
            $this->service()->cancel(SalePayment::sole(), $this->cashier, 'mistake');
            $this->fail('A settled tender must not be cancellable while its sale stands.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'Cancel the sale to withdraw it',
                $exception->errors()['payment'][0]
            );
        }

        $this->assertSame(PaymentStatus::Paid, SalePayment::sole()->status);
        $this->assertSame('100000.0000', Sale::find($sale['id'])->paid_total);
    }

    //
    // Configured methods.
    //

    public function test_a_configured_method_names_the_tender_and_its_rules_win(): void
    {
        $qriss = $this->method([
            'code' => 'QR-STORE',
            'name' => 'Scan Toko Kita',
            'channel' => PaymentChannel::Qris->value,
            'requires_reference' => true,
        ]);

        $cart = $this->cart();

        // The shop's own rule: no reference, no payment.
        $this->checkout([
            ['payment_method_id' => $qriss->id, 'amount' => '100000.0000'],
        ], ['cart_id' => $cart['id']])->assertStatus(422);
        $this->assertStringContainsString('needs a reference', $this->errorMessage('payments.reference'));

        $sale = $this->checkout([
            ['payment_method_id' => $qriss->id, 'amount' => '100000.0000', 'reference' => 'QR-0091'],
        ], ['cart_id' => $cart['id']])->assertCreated()->json('data');

        $this->assertSame('Scan Toko Kita', $sale['payments'][0]['method_name']);
        $this->assertSame('qris', $sale['payments'][0]['channel']);
        $this->assertSame($qriss->id, $sale['payments'][0]['payment_method_id']);
        $this->assertSame('QR-0091', $sale['payments'][0]['reference']);
    }

    public function test_a_payment_keeps_the_method_name_after_the_method_is_renamed(): void
    {
        $method = $this->method(['code' => 'GCASH', 'name' => 'GCash', 'channel' => PaymentChannel::EWallet->value]);
        $cart = $this->cart();

        $sale = $this->checkout([
            ['payment_method_id' => $method->id, 'amount' => '100000.0000'],
        ], ['cart_id' => $cart['id']])->assertCreated()->json('data');

        $method->update(['name' => 'Maya Wallet']);

        // Master data moved; the financial record did not.
        $this->assertSame('GCash', $sale['payments'][0]['method_name']);
        $this->assertSame('GCash', SalePayment::sole()->method_name);
        $this->assertSame('Maya Wallet', $method->fresh()->name);
    }

    public function test_a_method_belonging_to_another_shop_is_not_offered(): void
    {
        $foreign = PaymentMethod::factory()->for(Company::factory()->create())->create([
            'code' => 'NOPE', 'channel' => PaymentChannel::Qris->value,
        ]);

        $this->checkout([['payment_method_id' => $foreign->id, 'amount' => '100000.0000']])->assertStatus(422);
        $this->assertStringContainsString('not available at this outlet', $this->errorMessage('payments.payment_method_id'));
        $this->assertSame(0, Sale::count());
    }

    public function test_a_deactivated_method_is_not_taken(): void
    {
        $method = $this->method([
            'code' => 'OLD', 'channel' => PaymentChannel::Debit->value, 'is_active' => false,
        ]);

        $this->checkout([['payment_method_id' => $method->id, 'amount' => '100000.0000']])->assertStatus(422);
        $this->assertSame(0, Sale::count());
    }

    public function test_customer_credit_needs_a_customer_to_charge(): void
    {
        $credit = $this->method(['code' => 'KREDIT', 'name' => 'Account Credit', 'channel' => PaymentChannel::CustomerCredit->value]);
        $customer = Customer::factory()->for($this->company)->create();

        // A walk-in ticket has no account, and the till is the last moment anyone
        // can ask whose it is.
        $this->checkout([['payment_method_id' => $credit->id, 'amount' => '100000.0000']])->assertStatus(422);
        $this->assertStringContainsString('needs a customer', $this->errorMessage('payments.payment_method_id'));

        $cart = $this->cart(4, $customer);
        $this->checkout([['payment_method_id' => $credit->id, 'amount' => '100000.0000']], ['cart_id' => $cart['id']])
            ->assertCreated()
            ->assertJsonPath('data.payments.0.channel', 'customer_credit');
    }

    public function test_only_cash_may_hand_over_change(): void
    {
        // A card row carrying more than it paid would be a short drawer and a
        // receipt describing cash that never came back.
        $sale = $this->checkout([
            ['channel' => 'credit_card', 'amount' => '100000.0000', 'tendered' => '140000.0000'],
        ])->assertCreated()->json('data');

        $this->assertSame('0.0000', $sale['payments'][0]['change']);
        $this->assertSame('100000.0000', $sale['payments'][0]['tendered'], 'the extra is dropped, not recorded');
        $this->assertSame('0.0000', $sale['change_due']);
    }

    public function test_cash_handed_over_short_of_the_amount_is_refused(): void
    {
        $this->checkout([['channel' => 'cash', 'amount' => '100000.0000', 'tendered' => '90000.0000']])
            ->assertStatus(422);

        $this->assertStringContainsString('Only Rp 90.000 was handed over', $this->errorMessage('payments.tendered'));
        $this->assertSame(0, Sale::count());
    }

    public function test_a_sale_cannot_carry_an_endless_list_of_tenders(): void
    {
        $cart = $this->cart(20);
        $sale = $this->checkout([], ['cart_id' => $cart['id']])->assertCreated()->json('data');

        $service = $this->service();
        $model = Sale::find($sale['id']);

        // Eleven different amounts, so the duplicate guard is not what stops it.
        foreach (range(1, 10) as $step) {
            $service->takeByChannel($model, $this->cashier, [
                'channel' => 'cash', 'amount' => (string) ($step * 1000).'.0000',
            ]);
        }

        $this->expectException(ValidationException::class);
        $service->takeByChannel($model, $this->cashier, ['channel' => 'cash', 'amount' => '11000.0000']);
    }

    //
    // The provider seam.
    //

    public function test_a_method_naming_an_uninstalled_provider_is_refused_at_the_counter(): void
    {
        $method = $this->capturedMethod();

        $this->checkout([['payment_method_id' => $method->id, 'amount' => '100000.0000']])->assertStatus(422);
        $this->assertStringContainsString('not available on this installation', $this->errorMessage('payments.payment_method_id'));

        // The refusal is the point: the shop is not left holding a "paid" card
        // payment nobody confirmed.
        $this->assertSame(0, Sale::count());
        $this->assertSame(0, SalePayment::count());
    }

    public function test_an_installed_provider_decides_the_state_of_its_tender(): void
    {
        $this->app->instance(PaymentProviderRegistry::class, new PaymentProviderRegistry([
            new FakeProvider(status: PaymentStatus::Paid, reference: 'FAKE-4471'),
        ]));

        $method = $this->capturedMethod('fake');
        $cart = $this->cart();

        $sale = $this->checkout([['payment_method_id' => $method->id, 'amount' => '100000.0000']], ['cart_id' => $cart['id']])
            ->assertCreated()->json('data');

        $this->assertSame(PaymentStatus::Paid->value, $sale['payments'][0]['status']);
        $this->assertSame('FAKE-4471', $sale['payments'][0]['reference']);
        $this->assertSame('100000.0000', $sale['paid_total']);
    }

    public function test_a_provider_that_says_not_yet_leaves_the_ticket_outstanding(): void
    {
        $this->app->instance(PaymentProviderRegistry::class, new PaymentProviderRegistry([
            new FakeProvider(status: PaymentStatus::Pending),
        ]));

        $method = $this->capturedMethod('fake');
        $cart = $this->cart();

        $sale = $this->checkout([['payment_method_id' => $method->id, 'amount' => '100000.0000']], ['cart_id' => $cart['id']])
            ->assertCreated()->json('data');

        $this->assertSame(PaymentStatus::Pending->value, $sale['payments'][0]['status']);
        $this->assertSame('0.0000', $sale['paid_total']);
        $this->assertSame($sale['grand_total'], $sale['balance_due']);
        $this->assertNull($sale['stock_posted_at'], 'an unconfirmed tender does not empty a shelf');
    }

    public function test_a_provider_cannot_take_a_channel_it_does_not_speak(): void
    {
        $this->app->instance(PaymentProviderRegistry::class, new PaymentProviderRegistry([
            new FakeProvider(status: PaymentStatus::Paid),
        ]));

        $method = $this->method([
            'code' => 'CC', 'name' => 'Card', 'channel' => PaymentChannel::CreditCard->value,
            'provider' => 'fake',
        ]);

        $this->checkout([['payment_method_id' => $method->id, 'amount' => '100000.0000']])->assertStatus(422);
        $this->assertStringContainsString('cannot take credit card payments', $this->errorMessage('payments.payment_method_id'));
    }

    //
    // The till's method list.
    //

    public function test_an_unconfigured_shop_still_offers_its_till_a_method_list(): void
    {
        $methods = $this->service()->availableMethods($this->company->id);

        $this->assertNotEmpty($methods);
        $this->assertSame('Cash', $methods[0]->displayName());
        $this->assertTrue($methods[0]->takesTender());
        // The catalogue defaults, not the whole catalogue: nine kinds of money,
        // six of which a shop can take on day one.
        $this->assertCount(6, $methods);
        $this->assertNotContains('Customer credit', array_map(fn (PaymentMethodInterface $m) => $m->displayName(), $methods));
    }

    public function test_a_configured_shop_offers_only_its_active_methods_in_its_own_order(): void
    {
        $this->method(['code' => 'B', 'name' => 'Second', 'channel' => PaymentChannel::Qris->value, 'sort_order' => 20]);
        $this->method(['code' => 'A', 'name' => 'First', 'channel' => PaymentChannel::Cash->value, 'sort_order' => 10]);
        $this->method(['code' => 'Z', 'name' => 'Retired', 'channel' => PaymentChannel::Debit->value, 'is_active' => false]);

        $names = array_map(fn (PaymentMethodInterface $m) => $m->displayName(), $this->service()->availableMethods($this->company->id));

        $this->assertSame(['First', 'Second'], $names, 'the fallback must not leak into a configured shop');
    }

    public function test_the_available_endpoint_answers_the_till_in_till_order(): void
    {
        $this->method(['code' => 'B', 'name' => 'Second', 'channel' => PaymentChannel::Qris->value, 'sort_order' => 20]);
        $first = $this->method(['code' => 'A', 'name' => 'First', 'channel' => PaymentChannel::Cash->value, 'sort_order' => 10]);

        $response = $this->getJson('/api/v1/payment-methods/available', $this->headers())->assertOk();

        $this->assertSame('First', $response->json('data.0.name'));
        $this->assertTrue($response->json('data.0.takes_tender'), 'the till needs to know where to put the cash box');
        $this->assertFalse($response->json('data.1.takes_tender'));
        $this->assertSame($first->id, $response->json('data.0.id'));
    }

    //
    // Derived money — the property the whole engine rests on.
    //

    public function test_the_sale_money_is_derived_from_its_rows_however_they_arrive(): void
    {
        $cart = $this->cart();
        $sale = Sale::find((int) $this->checkout([], ['cart_id' => $cart['id']])->assertCreated()->json('data.id'));
        $service = $this->service();

        $cash = $service->takeByChannel($sale, $this->cashier, ['channel' => 'cash', 'amount' => '30000.0000']);
        $qris = $service->takeByChannel($sale, $this->cashier, ['channel' => 'qris', 'amount' => '30000.0000']);

        $this->assertSame('60000.0000', $sale->fresh()->paid_total);

        // Both withdrawals say `withSale` because both tenders settled: settled
        // money only stops counting when the document behind it is withdrawn.
        $service->cancel($qris, $this->cashier, 'wrong tender', withSale: true);
        $this->assertSame('30000.0000', $sale->fresh()->paid_total);

        $service->cancel($cash, $this->cashier, 'wrong tender', withSale: true);
        $this->assertSame('0.0000', $sale->fresh()->paid_total);

        // Recomputing an already-correct sale is a no-op, not an increment.
        $service->recalculate($sale->fresh());
        $this->assertSame('0.0000', $sale->fresh()->paid_total);
    }

    public function test_a_tenders_net_amount_is_what_the_shop_still_holds(): void
    {
        $cart = $this->cart();
        $sale = $this->checkout([['channel' => 'cash', 'amount' => '100000.0000']], ['cart_id' => $cart['id']])
            ->assertCreated()->json('data');

        $payment = SalePayment::sole();
        $payment->forceFill(['refunded_amount' => '40000.0000', 'status' => PaymentStatus::PartiallyRefunded])->save();

        $this->assertSame('60000.0000', $payment->fresh()->netAmount());
        $this->assertTrue($payment->fresh()->status->countsTowardPaid());

        // paid_total is a sum of nets, so recalculate() brings the ticket back in
        // line with money the shop actually still has.
        $this->service()->recalculate(Sale::find($sale['id']));
        $recalculated = Sale::find($sale['id']);
        $this->assertSame('60000.0000', $recalculated->paid_total);
        $this->assertSame('40000.0000', bcsub($recalculated->grand_total, $recalculated->paid_total, 4));
    }

    public function test_the_receipt_shows_method_amount_paid_change_and_reference(): void
    {
        $cart = $this->cart();
        $sale = $this->checkout([
            ['channel' => 'cash', 'amount' => '20000.0000', 'tendered' => '50000.0000'],
            ['channel' => 'bank_transfer', 'amount' => '80000.0000', 'reference' => 'TRF-2231'],
        ], ['cart_id' => $cart['id']])->assertCreated()->json('data');

        $html = $this->getJson("/api/v1/sales/{$sale['id']}/receipt?width=80", $this->headers())
            ->assertOk()->json('data.html');

        $this->assertStringContainsString('Cash', $html);
        $this->assertStringContainsString('Rp 20.000', $html);
        $this->assertStringContainsString('Rp 30.000', $html, 'the change the customer walked out with');
        $this->assertStringContainsString('Bank transfer', $html);
        $this->assertStringContainsString('TRF-2231', $html, 'the slip number, so a transfer is traceable');
        $this->assertStringContainsString('Paid', $html);
        $this->assertStringContainsString('Rp 100.000', $html, 'the roll-up both tenders add to');
    }

    public function test_the_invoice_document_lists_every_tender_with_its_reference(): void
    {
        $cart = $this->cart();
        $sale = $this->checkout([
            ['channel' => 'qris', 'amount' => '100000.0000', 'reference' => 'QR-55100'],
        ], ['cart_id' => $cart['id']])->assertCreated()->json('data');

        $html = $this->getJson("/api/v1/sales/{$sale['id']}/receipt?width=a4", $this->headers())
            ->assertOk()->json('data.html');

        $this->assertStringContainsString('<th>Method</th>', $html);
        $this->assertStringContainsString('<th>Reference</th>', $html);
        $this->assertStringContainsString('QR-55100', $html);
        $this->assertStringContainsString('QRIS', $html);
    }

    public function test_a_payment_is_numbered_by_the_sequence_engine_per_company(): void
    {
        $first = $this->checkout([['channel' => 'cash', 'amount' => '100000.0000']])->assertCreated()->json('data');
        $second = $this->checkout([['channel' => 'cash', 'amount' => '100000.0000']])->assertCreated()->json('data');

        $this->assertSame('PAY-2026-000001', $first['payments'][0]['number']);
        $this->assertSame('PAY-2026-000002', $second['payments'][0]['number']);
    }

    public function test_a_sale_payment_carries_the_register_and_cashier_who_took_it(): void
    {
        $register = Register::query()->where('company_id', $this->company->id)->sole();

        $this->checkout([['channel' => 'cash', 'amount' => '100000.0000']])->assertCreated();

        $payment = SalePayment::sole();
        $this->assertSame($register->id, $payment->register_id);
        $this->assertSame($this->cashier->id, $payment->received_by);
        $this->assertSame($this->company->id, $payment->company_id);
    }

    public function test_a_tender_is_recorded_against_the_configured_method_row(): void
    {
        $method = $this->method(['code' => 'CASHIER', 'name' => 'Cash drawer', 'channel' => PaymentChannel::Cash->value]);

        $this->checkout([['payment_method_id' => $method->id, 'amount' => '100000.0000']])->assertCreated();

        $this->assertDatabaseHas('sale_payments', [
            'payment_method_id' => $method->id,
            'method_name' => 'Cash drawer',
            'channel' => 'cash',
        ]);
    }
}

/**
 * A provider that answers what it is told to, so the seam itself can be tested
 * without a gateway. It never decides a state on its own — see the note on
 * PaymentProviderInterface about who is allowed to write one.
 */
class FakeProvider implements PaymentProviderInterface
{
    public function __construct(
        private PaymentStatus $status = PaymentStatus::Paid,
        private ?string $reference = null,
    ) {}

    public function key(): string
    {
        return 'fake';
    }

    /** @return list<PaymentChannel> */
    public function supportedChannels(): array
    {
        return [PaymentChannel::Qris, PaymentChannel::EWallet, PaymentChannel::VirtualAccount];
    }

    public function supports(PaymentChannel $channel): bool
    {
        return in_array($channel, $this->supportedChannels(), true);
    }

    public function prepare(SalePayment $payment, PaymentMethodInterface $method): array
    {
        return ['instructions' => 'scan the code'];
    }

    public function verify(SalePayment $payment): array
    {
        return ['status' => $this->status, 'reference' => $this->reference, 'raw' => ['provider' => 'fake']];
    }
}
