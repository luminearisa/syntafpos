<?php

namespace Tests\Feature;

use App\Enums\PaymentChannel;
use App\Enums\PaymentStatus;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The shop's own list of ways to be paid — "payment method harus configurable".
 *
 * Configuration looks like every other master-data module: CRUD, a code unique
 * inside a company, tenancy, permissions. What makes it its own file is that money
 * flows over these rows, so three things that ordinary master data may do are
 * refused here:
 *
 *  - delete a method a payment has already been taken on;
 *  - move a used method onto another channel;
 *  - let two methods claim to be the shop's default.
 *
 * Each of those is a shop editing the meaning of a receipt after it was printed.
 */
class PaymentMethodTest extends TestCase
{
    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->authenticatedUser([
            'payment_methods.view', 'payment_methods.create',
            'payment_methods.update', 'payment_methods.delete',
        ]);
    }

    //
    // Fixtures
    //

    /**
     * The shop `authenticatedUser()` built for the owner in setUp.
     *
     * Every later authenticatedUser() call moves $this->company to *its* new tree,
     * so tests anchor their fixtures and their headers to the owner's company here
     * rather than to whatever $this->company happens to mean at the time.
     */
    protected function company(): Company
    {
        return $this->owner->companies()->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function headers(?User $user = null, array $context = []): array
    {
        $user ??= $this->owner;

        return $this->authHeaders($user, array_filter([
            'company_id' => $context['company_id'] ?? $user->companies()->first()?->id,
            'branch_id' => $context['branch_id'] ?? $user->branches()->first()?->id,
            'warehouse_id' => $context['warehouse_id'] ?? $user->warehouses()->first()?->id,
            'register_id' => $context['register_id'] ?? $user->registers()->first()?->id,
        ], fn ($value) => $value !== null));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function method(array $attributes = []): PaymentMethod
    {
        return PaymentMethod::factory()->for($this->company())->create($attributes);
    }

    /**
     * POST /payment-methods as $user, defaulting to the owner. Named `createMethod`
     * rather than `post` because the base test case owns that name for the HTTP verb.
     *
     * Everything but the tender itself is defaulted, so a call reads as the one
     * thing each test is actually configuring.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function createMethod(array $attributes = [], ?User $user = null): TestResponse
    {
        return $this->postJson('/api/v1/payment-methods', array_merge([
            'company_id' => $this->company()->id,
            'code' => 'QRIS',
            'name' => 'QRIS',
            'channel' => PaymentChannel::Qris->value,
        ], $attributes), $this->headers($user));
    }

    /**
     * A tender already taken on a method, without the whole sale engine behind it.
     *
     * What the tests below ask is only "has money gone through this row", which is
     * a fact about the payment table, not about how it got there.
     */
    protected function usedBy(PaymentMethod $method): SalePayment
    {
        $sale = Sale::create([
            'company_id' => $method->company_id,
            'number' => 'INV-USED-'.uniqid(),
            'date' => now()->toDateString(),
        ]);
        $sale->forceFill(['grand_total' => '100000.0000'])->save();

        return SalePayment::create([
            'sale_id' => $sale->id,
            'company_id' => $method->company_id,
            'payment_method_id' => $method->id,
            'number' => 'PAY-USED-'.uniqid(),
            'channel' => $method->channel->value,
            'method_name' => $method->name,
            'amount' => '100000.0000',
            'status' => PaymentStatus::Paid->value,
        ]);
    }

    //
    // Configuration.
    //

    public function test_a_method_can_be_configured_and_read_back(): void
    {
        $created = $this->createMethod([
            'code' => 'QRIS-BANK',
            'name' => 'QRIS BCA',
            'channel' => PaymentChannel::Qris->value,
            'icon' => 'qr-code',
            'description' => 'Scan with any bank app',
            'requires_reference' => true,
            'sort_order' => 7,
            'settings' => ['terminal' => 'B-221'],
        ])->assertCreated()
            ->assertJsonPath('data.code', 'QRIS-BANK')
            ->assertJsonPath('data.channel', 'qris')
            ->assertJsonPath('data.channel_label', 'QRIS')
            ->assertJsonPath('data.requires_reference', true)
            ->assertJsonPath('data.sort_order', 7);

        $id = $created->json('data.id');

        // The behaviour flags the till reads are derived from the channel, not
        // typed by whoever configured the row.
        $this->assertFalse($created->json('data.takes_tender'));
        $this->assertFalse($created->json('data.uses_customer_account'));
        $this->assertSame(['terminal' => 'B-221'], $created->json('data.settings'));

        $this->getJson("/api/v1/payment-methods/{$id}", $this->headers())
            ->assertOk()
            ->assertJsonPath('data.name', 'QRIS BCA');

        $this->assertDatabaseHas('payment_methods', ['id' => $id, 'channel' => 'qris']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment_method.create']);
    }

    public function test_only_cash_is_reported_as_handing_change_back(): void
    {
        foreach (PaymentChannel::cases() as $channel) {
            $method = $this->method(['channel' => $channel->value]);

            $this->assertSame(
                $channel->takesTender(),
                (bool) $this->getJson("/api/v1/payment-methods/{$method->id}", $this->headers())
                    ->assertOk()->json('data.takes_tender'),
                $channel->value.' must report change exactly as its channel behaves'
            );
        }
    }

    public function test_the_tills_flags_cannot_be_typed_into_the_form(): void
    {
        // A shop that could tick "gives change" on a card method ends up with a
        // drawer short at shift close and a receipt describing cash that never
        // left. The field does not exist, so the payload is simply ignored.
        $this->createMethod(['channel' => PaymentChannel::CreditCard->value, 'takes_tender' => true])
            ->assertCreated()
            ->assertJsonPath('data.takes_tender', false);
    }

    public function test_the_channel_and_name_are_required(): void
    {
        $this->createMethod(['channel' => 'paypal'])->assertStatus(422)->assertJsonValidationErrors('channel');
        $this->createMethod(['name' => ''])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->createMethod(['channel' => ''])->assertStatus(422)->assertJsonValidationErrors('channel');
    }

    public function test_a_code_is_unique_inside_a_company_and_free_across_companies(): void
    {
        $this->createMethod(['code' => 'QRIS', 'channel' => PaymentChannel::Qris->value])->assertCreated();

        $this->createMethod(['code' => 'QRIS', 'channel' => PaymentChannel::Debit->value])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        // The same word means something else in another shop, which is that shop's
        // business: codes are how a company names its own methods. The second call
        // builds that other shop and its owner, and their QRIS is accepted.
        $this->assertDatabaseHas('payment_methods', ['company_id' => $this->company()->id, 'code' => 'QRIS']);

        $other = $this->authenticatedUser(['payment_methods.create']);

        $this->postJson('/api/v1/payment-methods', [
            'company_id' => $other->companies()->first()->id,
            'code' => 'QRIS',
            'name' => 'QRIS',
            'channel' => PaymentChannel::Qris->value,
        ], $this->headers($other))->assertCreated();
    }

    public function test_a_method_can_be_renamed_reordered_and_deactivated(): void
    {
        $method = $this->method(['code' => 'OLD', 'name' => 'Old name', 'channel' => PaymentChannel::Debit->value]);

        $this->putJson("/api/v1/payment-methods/{$method->id}", [
            'name' => 'Debit Mesin EDC',
            'sort_order' => 3,
            'is_active' => false,
            'requires_reference' => true,
        ], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.name', 'Debit Mesin EDC')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.requires_reference', true);

        $this->assertSame('Debit Mesin EDC', $method->fresh()->name);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment_method.update']);
    }

    public function test_an_unused_method_can_be_retired(): void
    {
        $method = $this->method(['code' => 'TEMP']);

        $this->deleteJson("/api/v1/payment-methods/{$method->id}", [], $this->headers())->assertOk();

        $this->assertSoftDeleted('payment_methods', ['id' => $method->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment_method.delete']);
    }

    public function test_a_method_that_has_taken_money_cannot_be_deleted(): void
    {
        $method = $this->method(['code' => 'USED', 'name' => 'Used method']);
        $this->usedBy($method);

        $denied = $this->deleteJson("/api/v1/payment-methods/{$method->id}", [], $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_method');

        // The refusal says what to do instead, because "you cannot delete this" is
        // an instruction to break something else otherwise.
        $this->assertStringContainsString(
            'Deactivate it instead',
            implode(' | ', (array) $denied->json('errors.payment_method'))
        );

        $this->assertNotSoftDeleted('payment_methods', ['id' => $method->id]);
    }

    public function test_a_method_that_has_taken_money_cannot_change_channel(): void
    {
        $method = $this->method(['code' => 'LOCKED', 'channel' => PaymentChannel::Qris->value]);
        $this->usedBy($method);

        // A QRIS button reclassified as cash would leave its past tenders
        // describing change from a drawer that never opened.
        $this->putJson("/api/v1/payment-methods/{$method->id}", [
            'channel' => PaymentChannel::Cash->value,
        ], $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors('channel');

        $this->assertSame(PaymentChannel::Qris, $method->fresh()->channel);

        // Renaming it is still fine — the payments keep their own copy.
        $this->putJson("/api/v1/payment-methods/{$method->id}", [
            'name' => 'QRIS Lama',
        ], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.name', 'QRIS Lama');
    }

    public function test_an_unused_method_may_be_moved_to_another_channel(): void
    {
        $method = $this->method(['code' => 'FIX', 'channel' => PaymentChannel::Other->value]);

        $this->putJson("/api/v1/payment-methods/{$method->id}", [
            'channel' => PaymentChannel::EWallet->value,
        ], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.channel', 'e_wallet');
    }

    public function test_a_company_has_exactly_one_default_method(): void
    {
        $cash = $this->method(['code' => 'CASH', 'channel' => PaymentChannel::Cash->value, 'is_default' => true]);
        $this->assertTrue($cash->is_default);

        $qris = $this->createMethod(['code' => 'QR2', 'name' => 'QRIS Dua', 'channel' => PaymentChannel::Qris->value, 'is_default' => true])
            ->assertCreated()->json('data.id');

        // Moving the default onto a second method takes it off the first: the shop
        // has said which one it means, and making it un-tick the other to satisfy
        // the server is a form that fights its own user.
        $this->assertFalse($cash->fresh()->is_default);
        $this->assertTrue(PaymentMethod::find($qris)->is_default);
        $this->assertSame(1, PaymentMethod::query()->where('company_id', $this->company()->id)->where('is_default', true)->count());
    }

    public function test_a_default_can_be_moved_back_onto_another_method(): void
    {
        $cash = $this->method(['code' => 'CASH', 'channel' => PaymentChannel::Cash->value, 'is_default' => true]);
        $qris = $this->method(['code' => 'QR2', 'channel' => PaymentChannel::Qris->value]);

        $this->putJson("/api/v1/payment-methods/{$qris->id}", ['is_default' => true], $this->headers())->assertOk();
        $this->putJson("/api/v1/payment-methods/{$cash->id}", ['is_default' => true], $this->headers())->assertOk();

        $this->assertTrue($cash->fresh()->is_default);
        $this->assertFalse($qris->fresh()->is_default);
    }

    //
    // The provider seam, from the configuration side.
    //

    public function test_a_method_naming_an_uninstalled_provider_is_refused_while_saving(): void
    {
        // 3.3 installs no gateway, so nothing answers to `midtrans` yet — and an
        // owner should learn that while filling the form rather than from a queue
        // of declined payments at the counter.
        $denied = $this->createMethod(['code' => 'MT', 'name' => 'Midtrans QRIS', 'provider' => 'midtrans'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('provider');

        $this->assertStringContainsString(
            'is installed on this server',
            $this->fieldMessage($denied, 'provider')
        );

        $this->assertDatabaseMissing('payment_methods', ['code' => 'MT']);
    }

    public function test_a_method_created_without_saying_active_is_reported_active(): void
    {
        // The till lists rows by `is_active`, so a create response that echoes the
        // column as unset while the row is live would hand a client a list and a
        // detail screen that disagree. The database default is the answer.
        $this->createMethod(['code' => 'NEW1', 'name' => 'New method'])
            ->assertCreated()
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.is_default', false);
    }

    public function test_a_provider_naming_nothing_leaves_the_method_recording_tenders_directly(): void
    {
        $this->createMethod(['code' => 'PLAIN1', 'provider' => null])->assertCreated()->assertJsonPath('data.provider', null);
        $this->createMethod(['code' => 'PLAIN2', 'provider' => ''])->assertCreated()->assertJsonPath('data.provider', null);
    }

    //
    // The till's view of the same rows.
    //

    public function test_the_till_sees_active_methods_in_the_shops_own_order(): void
    {
        $this->method(['code' => 'B', 'name' => 'Second', 'channel' => PaymentChannel::Qris->value, 'sort_order' => 20]);
        $this->method(['code' => 'A', 'name' => 'First', 'channel' => PaymentChannel::Cash->value, 'sort_order' => 10]);
        $this->method(['code' => 'C', 'name' => 'Retired', 'channel' => PaymentChannel::Debit->value, 'is_active' => false]);

        $names = collect($this->getJson('/api/v1/payment-methods/available', $this->headers())
            ->assertOk()->json('data'))->pluck('name')->all();

        $this->assertSame(['First', 'Second'], $names);
    }

    public function test_the_till_falls_back_to_the_catalogue_until_the_shop_configures_anything(): void
    {
        $data = $this->getJson('/api/v1/payment-methods/available', $this->headers())
            ->assertOk()->json('data');

        // A shop whose owner has never opened this screen still has to be able to
        // take cash: a blank answer here stops a shop selling.
        $this->assertCount(6, $data);
        $this->assertSame('Cash', $data[0]['name']);
        $this->assertNull($data[0]['id'], 'a fallback row is not a configuration the shop owns');
        $this->assertTrue($data[0]['is_default']);
        $this->assertTrue($data[0]['takes_tender']);
        $this->assertFalse($data[1]['takes_tender']);

        $this->method(['code' => 'REAL', 'name' => 'Our own QRIS', 'channel' => PaymentChannel::Qris->value]);

        $configured = $this->getJson('/api/v1/payment-methods/available', $this->headers())
            ->assertOk()->json('data');

        // One real row is enough: the fallback is a shop with no configuration, not
        // a shop whose configuration is thin.
        $this->assertCount(1, $configured);
        $this->assertSame('Our own QRIS', $configured[0]['name']);
    }

    public function test_the_admin_list_reports_how_much_has_used_each_method(): void
    {
        $used = $this->method(['code' => 'USED2', 'channel' => PaymentChannel::Qris->value]);
        $fresh = $this->method(['code' => 'FRESH', 'channel' => PaymentChannel::Qris->value]);
        $this->usedBy($used);
        $this->usedBy($used);

        $rows = collect($this->getJson('/api/v1/payment-methods', $this->headers())
            ->assertOk()->json('data'))->keyBy('code');

        $this->assertSame(2, $rows['USED2']['payments_count']);
        $this->assertSame(0, $rows['FRESH']['payments_count']);
    }

    public function test_the_admin_list_filters_by_channel_search_and_active_state(): void
    {
        $this->method(['code' => 'CASH1', 'name' => 'Drawer cash', 'channel' => PaymentChannel::Cash->value]);
        $this->method(['code' => 'QR1', 'name' => 'Scan QR', 'channel' => PaymentChannel::Qris->value]);
        $this->method(['code' => 'OFF1', 'name' => 'QRIS offline', 'channel' => PaymentChannel::Qris->value, 'is_active' => false]);

        $this->assertJsonCountOnPath(
            2,
            $this->getJson('/api/v1/payment-methods?channel=qris', $this->headers())->assertOk()
        );
        $this->assertJsonCountOnPath(
            1,
            $this->getJson('/api/v1/payment-methods?channel=qris&active_only=1', $this->headers())->assertOk()
        );
        $this->assertJsonCountOnPath(
            1,
            $this->getJson('/api/v1/payment-methods?search=Drawer', $this->headers())->assertOk()
        );
        // A code fragment finds it too, which is how an operator searches a long list.
        $this->assertJsonCountOnPath(
            1,
            $this->getJson('/api/v1/payment-methods?search=OFF', $this->headers())->assertOk()
        );
    }

    //
    // Authorisation and tenancy.
    //

    public function test_configuring_payment_methods_is_its_own_permission(): void
    {
        $viewer = $this->authenticatedUser(['payment_methods.view']);
        $teller = $this->authenticatedUser(['sales.create']);

        $method = $this->method();

        $this->getJson('/api/v1/payment-methods', $this->headers($viewer))->assertOk();
        $this->getJson('/api/v1/payment-methods/available', $this->headers($viewer))->assertOk();

        // Reading the list is not the same as deciding how the shop is paid, and a
        // cashier who can take a tender cannot invent one.
        $this->postJson('/api/v1/payment-methods', [
            'company_id' => $this->company()->id, 'code' => 'NO', 'name' => 'No', 'channel' => 'cash',
        ], $this->headers($viewer))->assertStatus(403);

        $this->getJson('/api/v1/payment-methods', $this->headers($teller))->assertStatus(403);
        $this->putJson("/api/v1/payment-methods/{$method->id}", ['name' => 'Renamed'], $this->headers($viewer))->assertStatus(403);
        $this->deleteJson("/api/v1/payment-methods/{$method->id}", [], $this->headers($viewer))->assertStatus(403);
    }

    public function test_a_method_list_is_limited_to_the_shops_a_user_belongs_to(): void
    {
        $mine = $this->method(['code' => 'MINE']);
        $theirs = PaymentMethod::factory()->for(Company::factory()->create())->create(['code' => 'THEIRS']);

        $codes = collect($this->getJson('/api/v1/payment-methods?per_page=100', $this->headers())
            ->assertOk()->json('data'))->pluck('code')->all();

        $this->assertContains('MINE', $codes);
        $this->assertNotContains('THEIRS', $codes);

        $this->getJson("/api/v1/payment-methods/{$theirs->id}", $this->headers())->assertStatus(403);
        $this->putJson("/api/v1/payment-methods/{$theirs->id}", ['name' => 'Hijacked'], $this->headers())->assertStatus(403);
        $this->deleteJson("/api/v1/payment-methods/{$theirs->id}", [], $this->headers())->assertStatus(403);

        // The rival's row is untouched by all three attempts — the name is the one
        // the factory gave it, which a rename would have replaced.
        $this->assertSame($theirs->name, $theirs->fresh()->name);
        $this->assertNotSoftDeleted('payment_methods', ['id' => $theirs->id]);
        $this->assertSame($mine->name, $mine->fresh()->name);
    }

    public function test_a_method_cannot_be_configured_onto_an_unrelated_company(): void
    {
        $rival = Company::factory()->create();

        $this->createMethod(['company_id' => $rival->id])
            ->assertStatus(403);

        $this->assertDatabaseMissing('payment_methods', ['company_id' => $rival->id]);
    }

    public function test_the_api_answers_with_the_standard_envelope(): void
    {
        // A row has to exist for `data` to have a shape at all — an empty page
        // passes a structure check against a list the assertion never looked at.
        $this->method(['code' => 'ENV', 'name' => 'Envelope']);

        $this->getJson('/api/v1/payment-methods', $this->headers())
            ->assertOk()
            ->assertJsonStructure([
                'success', 'message', 'data' => [['id', 'code', 'name', 'channel', 'takes_tender']],
                'meta' => ['total', 'per_page', 'current_page', 'last_page'],
            ]);
    }

    //
    // Helpers
    //

    protected function fieldMessage(TestResponse $response, string $key): string
    {
        return implode(' | ', (array) ($response->json("errors.{$key}") ?? []));
    }

    protected function assertJsonCountOnPath(int $count, TestResponse $response): TestResponse
    {
        return $response->assertJsonCount($count, 'data');
    }
}
