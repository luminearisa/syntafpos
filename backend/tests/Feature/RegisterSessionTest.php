<?php

namespace Tests\Feature;

use App\Enums\CashMovementType;
use App\Enums\PaymentStatus;
use App\Enums\RegisterSessionStatus;
use App\Enums\SaleStatus;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Register;
use App\Models\RegisterSession;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The cash register: opening a drawer, moving money through it, counting it shut,
 * and who may sign off the difference.
 *
 * One test per line of the Subphase 3.4 work order — Open, Duplicate open, Sale,
 * Cash in, Cash out, Close, Variance, Approval, Unauthorized reopen — plus the two
 * its own formula implies and does not list: change must not count as cash the
 * drawer kept, and membership of a shift must be a stamped foreign key rather than
 * a date range.
 *
 * The organising question is the payment tests' question moved down a level: not
 * "did a row appear" but "does the drawer add up". Expected cash is a sum over other
 * tables, so a test that only checked the shift's own columns would pass while the
 * reconciliation was wrong by exactly the change handed back, or by a cancelled
 * sale's takings.
 */
class RegisterSessionTest extends TestCase
{
    protected User $cashier;

    protected Unit $unit;

    protected Product $coffee;

    protected ?TestResponse $last = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashier = $this->authenticatedUser([
            'pos.view', 'pos.transact',
            'sales.view', 'sales.create', 'sales.complete', 'sales.cancel',
            'register_sessions.view', 'register_sessions.open',
            'register_sessions.process', 'register_sessions.close',
        ]);

        $this->unit = Unit::factory()->for($this->shop())->create(['code' => 'PCS']);
        $this->coffee = Product::factory()->for($this->shop())->create([
            'sku' => 'COF-P34',
            'name' => 'Coffee House Blend',
            'default_unit_id' => $this->unit->id,
            'is_sellable' => true,
            'is_active' => true,
            // Untracked: this file is about the drawer, and a stock shortfall
            // failing a checkout for an unrelated reason reads as a shift bug.
            'track_inventory' => false,
            // 25.000 a unit, so four of them are the round 100.000 every sum below
            // is written out as.
            'selling_price' => '25000.0000',
        ]);
    }

    //
    // Fixtures
    //

    /**
     * The shop `authenticatedUser()` built in setUp.
     *
     * Named `shop` rather than `company` because the base test case owns that name
     * for the mutable property, and every later authenticatedUser() call moves that
     * property to *its* new tree. Fixtures and headers anchor here instead.
     */
    protected function shop(): Company
    {
        return $this->cashier->companies()->firstOrFail();
    }

    protected function till(): Register
    {
        return $this->cashier->registers()->firstOrFail();
    }

    /**
     * @param  array<string, int|string|null>  $context
     */
    protected function headers(?User $user = null, array $context = []): array
    {
        $user ??= $this->cashier;

        return $this->authHeaders($user, array_filter([
            'company_id' => $context['company_id'] ?? $user->companies()->first()?->id,
            'branch_id' => $context['branch_id'] ?? $user->branches()->first()?->id,
            'warehouse_id' => $context['warehouse_id'] ?? $user->warehouses()->first()?->id,
            'register_id' => $context['register_id'] ?? $user->registers()->first()?->id,
        ], fn ($value) => $value !== null));
    }

    /**
     * A second person in the cashier's own shop, holding only the listed permissions.
     *
     * Needed because the approval and reopen rules are entirely about *who*: two
     * users inside one company is the smallest shape that shows a permission is
     * doing the work, rather than a request being answered for whoever sent it.
     *
     * @param  list<string>  $permissions
     */
    protected function colleague(array $permissions): User
    {
        $company = $this->shop();
        $register = $this->till();

        $user = User::factory()->create();
        $user->companies()->attach($company->id);
        $user->branches()->attach($register->branch_id);
        $user->warehouses()->attach($register->warehouse_id);
        $user->registers()->attach($register->id);

        $role = Role::create([
            'company_id' => $company->id,
            'name' => 'shift_'.uniqid(),
            'display_name' => 'Shift Role',
        ]);

        $role->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id')->all());
        $user->roles()->attach($role->id);
        $user->clearPermissionCache($company->id);

        return $user;
    }

    /** A second drawer in the same shop, worked by the same cashier. */
    protected function secondTill(): Register
    {
        $register = $this->till();

        $other = Register::factory()->for($this->shop())->create([
            'branch_id' => $register->branch_id,
            'warehouse_id' => $register->warehouse_id,
            'code' => 'REG-02',
            'name' => 'Register 02',
        ]);

        $this->cashier->registers()->attach($other->id);

        return $other;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function openRegister(array $input = [], ?User $user = null, ?int $registerId = null): TestResponse
    {
        return $this->last = $this->postJson('/api/v1/register-sessions', array_merge([
            'opening_balance' => '200000.0000',
            'register_id' => $registerId ?? $this->till()->id,
        ], $input), $this->headers($user));
    }

    /** Open a shift and hand back its id — most tests need the id and nothing else. */
    protected function shiftId(array $input = [], ?User $user = null, ?int $registerId = null): int
    {
        return (int) $this->openRegister($input, $user, $registerId)->assertCreated()->json('data.id');
    }

    /**
     * Ring up and settle $quantity units for cash, tendering $tendered.
     *
     * @return array<string, mixed> the sale as the API returned it
     */
    protected function sell(int $quantity = 4, ?string $tendered = null): array
    {
        $cartId = (int) $this->getJson('/api/v1/pos/cart', $this->headers())->assertOk()->json('data.id');

        $cart = $this->postJson("/api/v1/pos/cart/{$cartId}/items", [
            'product_id' => $this->coffee->id,
            'quantity' => (string) $quantity,
        ], $this->headers())->assertCreated()->json('data.cart');

        $amount = $cart['grand_total'];

        $response = $this->postJson('/api/v1/sales', [
            'cart_id' => $cart['id'],
            'payments' => [[
                'channel' => 'cash',
                'amount' => $amount,
                'tendered' => $tendered ?? $amount,
            ]],
        ], $this->headers());

        $this->last = $response;

        return $response->assertCreated()->json('data');
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function movement(int $shiftId, array $input = [], ?User $user = null): TestResponse
    {
        return $this->last = $this->postJson("/api/v1/register-sessions/{$shiftId}/movements", array_merge([
            'type' => CashMovementType::Expense->value,
            'amount' => '50000.0000',
            'reason' => 'Cash paid to the supplier for milk',
        ], $input), $this->headers($user));
    }

    /** @param array<string, mixed> $input */
    protected function close(int $shiftId, array $input, ?User $user = null, ?int $registerId = null): TestResponse
    {
        return $this->last = $this->postJson("/api/v1/register-sessions/{$shiftId}/close", $input, $this->headers($user));
    }

    /** @param array<string, mixed> $input */
    protected function approve(int $shiftId, array $input = [], ?User $user = null): TestResponse
    {
        return $this->last = $this->postJson("/api/v1/register-sessions/{$shiftId}/approve", $input, $this->headers($user));
    }

    /** @param array<string, mixed> $input */
    protected function reopen(int $shiftId, array $input = [], ?User $user = null): TestResponse
    {
        return $this->last = $this->postJson("/api/v1/register-sessions/{$shiftId}/reopen", $input, $this->headers($user));
    }

    protected function show(int $shiftId, ?User $user = null): TestResponse
    {
        return $this->last = $this->getJson("/api/v1/register-sessions/{$shiftId}", $this->headers($user));
    }

    /** The figure the shift screen shows, which is the one the close is judged against. */
    protected function expected(int $shiftId): string
    {
        return (string) $this->show($shiftId)->assertOk()->json('data.expected_cash');
    }

    /** @return array<string, string> the summary a shift response carries */
    protected function summaryOf(int $shiftId): array
    {
        return (array) $this->show($shiftId)->assertOk()->json('data.summary');
    }

    protected function errorMessage(string $key): string
    {
        $errors = (array) $this->last?->json('errors');

        return implode(' | ', (array) ($errors[$key] ?? []));
    }

    //
    // The work order's list, one test per line.
    //

    public function test_open_a_register_puts_float_in_a_drawer_and_starts_a_shift(): void
    {
        $shift = $this->openRegister(['opening_balance' => '200000.0000'])
            ->assertCreated()
            ->assertJsonPath('data.status', RegisterSessionStatus::Open->value)
            ->assertJsonPath('data.status_label', 'Open')
            ->assertJsonPath('data.opening_balance', '200000.0000')
            // Expected cash is live from the first moment: what a till shows while
            // the drawer is working is the figure the close will be judged against.
            ->assertJsonPath('data.expected_cash', '200000.0000')
            ->assertJsonPath('data.summary.cash_sales', '0.0000')
            ->assertJsonPath('data.awaiting_approval', false)
            ->json('data');

        $this->assertMatchesRegularExpression('/^SHIFT-\d{4}-\d{4}$/', $shift['number']);
        $this->assertSame($this->till()->id, $shift['register_id']);
        $this->assertSame($this->till()->code, $shift['register_code']);
        $this->assertSame($this->cashier->id, $shift['cashier_id']);
        $this->assertSame($this->cashier->name, $shift['opened_by']);
        $this->assertNull($shift['closed_at']);
        $this->assertNull($shift['actual_balance'], 'nothing has been counted yet');

        $row = RegisterSession::findOrFail($shift['id']);
        $this->assertSame('register:'.$this->till()->id, $row->register_open_key);
        $this->assertSame($this->shop()->id, $row->company_id);
        $this->assertSame($this->till()->warehouse_id, $row->warehouse_id);
    }

    public function test_the_shift_names_its_register_its_cashier_and_its_branch(): void
    {
        $cashier = $this->colleague(['register_sessions.open']);

        $shift = $this->openRegister(['cashier_id' => $cashier->id])
            ->assertCreated()
            ->json('data');

        $this->assertSame($cashier->id, $shift['cashier_id']);
        $this->assertSame($cashier->name, $shift['cashier']['name']);
        // Who the shift is *on* is not who pressed the button, and the two have to
        // stay separately readable: the variance conversation is with the cashier.
        $this->assertSame($this->cashier->name, $shift['opened_by']);
        $this->assertSame($this->till()->branch_id, $shift['branch_id']);
        $this->assertNotNull($shift['branch_name']);
    }

    public function test_opening_a_register_demands_the_float_it_was_opened_with(): void
    {
        $this->last = $this->postJson('/api/v1/register-sessions', [], $this->headers());
        $this->last->assertStatus(422);

        $this->assertStringContainsString('float', $this->errorMessage('opening_balance'));

        $this->openRegister(['opening_balance' => '0'])->assertStatus(422);
        $this->assertStringContainsString('more than zero', $this->errorMessage('opening_balance'));

        $this->assertSame(0, RegisterSession::count());
    }

    public function test_duplicate_open_a_register_is_refused(): void
    {
        $first = $this->openRegister()->assertCreated()->json('data');

        $this->openRegister(['opening_balance' => '100000.0000'])->assertStatus(422);

        $message = $this->errorMessage('register_id');
        $this->assertStringContainsString('already open', $message);
        $this->assertStringContainsString($first['number'], $message);

        // One drawer, one shift: the refusal left no second row behind.
        $this->assertSame(1, RegisterSession::count());

        // A second drawer in the same shop is fine — the constraint is per register.
        $this->shiftId(['opening_balance' => '100000.0000'], null, $this->secondTill()->id);
        $this->assertSame(2, RegisterSession::count());
    }

    public function test_one_open_shift_per_register_is_held_by_the_database_as_well(): void
    {
        // The service's read is the polite answer; this index is the one that holds
        // when two cashiers press Open in the same millisecond. Asserting the
        // constraint itself, because a test that only exercised the read would pass
        // on a schema that had quietly lost the unique index.
        $this->shiftId();

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('register_sessions')->insert([
            'company_id' => $this->shop()->id,
            'branch_id' => $this->till()->branch_id,
            'register_id' => $this->till()->id,
            'cashier_id' => $this->cashier->id,
            'number' => 'SHIFT-MANUAL-1',
            'status' => RegisterSessionStatus::Open->value,
            'register_open_key' => 'register:'.$this->till()->id,
            'opening_balance' => '1.0000',
            'opened_at' => now(),
            'requires_approval' => false,
            'is_approved' => false,
            'reopen_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_sale_lands_on_the_open_shift_and_moves_expected_cash(): void
    {
        $shiftId = $this->shiftId(['opening_balance' => '200000.0000']);

        $sale = $this->sell(4);

        $this->assertSame('100000.0000', $sale['grand_total']);
        $this->assertSame($shiftId, $sale['register_session_id'], 'the ticket must be traceable to a drawer');

        // The tender inherits the shift the ticket was raised on rather than looking
        // the register up again, so a sale settled across a close cannot be counted
        // twice or by whichever shift happened to be open at the time.
        $payment = RegisterSession::find($shiftId)->payments()->first();
        $this->assertNotNull($payment);
        $this->assertSame($shiftId, (int) $payment->register_session_id);

        $this->assertSame('300000.0000', $this->expected($shiftId));

        $summary = $this->summaryOf($shiftId);
        $this->assertSame('100000.0000', $summary['cash_sales']);
        $this->assertSame('0.0000', $summary['non_cash_sales']);
        $this->assertSame('1', $summary['sales_count']);
        $this->assertSame('1', $summary['tender_count']);
    }

    public function test_a_sale_can_still_be_rung_up_with_no_shift_open(): void
    {
        // The default, and it has to stay the default: an import, a head-office
        // order, or a shop that simply counts its drawer at the end of the day must
        // not be stopped from selling by a shift nobody opened.
        $sale = $this->sell(2);

        $this->assertNull($sale['register_session_id']);
        $this->assertSame(SaleStatus::Completed->value, $sale['status']);
    }

    public function test_cash_in_raises_expected_cash(): void
    {
        $shiftId = $this->shiftId(['opening_balance' => '200000.0000']);

        $this->movement($shiftId, [
            'type' => CashMovementType::CashInjection->value,
            'amount' => '100000.0000',
            'reason' => 'Additional float from the safe',
        ])->assertCreated()
            ->assertJsonPath('data.type', 'cash_injection')
            ->assertJsonPath('data.type_label', 'Cash injection')
            ->assertJsonPath('data.group', 'in')
            ->assertJsonPath('data.direction', 'in')
            ->assertJsonPath('data.signed_amount', '100000.0000')
            ->assertJsonPath('data.user.id', $this->cashier->id);

        $this->assertSame('300000.0000', $this->expected($shiftId));

        $summary = $this->summaryOf($shiftId);
        $this->assertSame('100000.0000', $summary['cash_in']);
        $this->assertSame('0.0000', $summary['cash_out']);
        $this->assertSame('1', $summary['movement_count']);
    }

    public function test_other_income_is_cash_in_too(): void
    {
        $shiftId = $this->shiftId(['opening_balance' => '200000.0000']);

        $this->movement($shiftId, [
            'type' => CashMovementType::OtherIncome->value,
            'amount' => '15000.0000',
            'reason' => 'Sale made on the storeroom door',
        ])->assertCreated()->assertJsonPath('data.group', 'in');

        $this->assertSame('215000.0000', $this->expected($shiftId));
    }

    public function test_cash_out_lowers_expected_cash(): void
    {
        $shiftId = $this->shiftId(['opening_balance' => '200000.0000']);

        foreach ([
            [CashMovementType::Expense, '50000.0000', 'Paid the cleaning supplier in cash'],
            [CashMovementType::Withdrawal, '30000.0000', 'Bank drop collection'],
            [CashMovementType::PettyCash, '20000.0000', 'Petty cash for the kitchen'],
        ] as [$type, $amount, $reason]) {
            $this->movement($shiftId, ['type' => $type->value, 'amount' => $amount, 'reason' => $reason])
                ->assertCreated()
                ->assertJsonPath('data.group', 'out')
                ->assertJsonPath('data.direction', 'out')
                ->assertJsonPath('data.signed_amount', '-'.$amount);
        }

        $this->assertSame('100000.0000', $this->expected($shiftId));

        $summary = $this->summaryOf($shiftId);
        $this->assertSame('100000.0000', $summary['cash_out'], 'all three outflow reasons share one report line');
        $this->assertSame('0.0000', $summary['cash_in']);
        $this->assertSame('3', $summary['movement_count']);

        // The money is attributable: amount, reason, user, reference, timestamp.
        $row = DB::table('cash_movements')->where('register_session_id', $shiftId)->orderBy('id')->first();
        $this->assertSame($this->cashier->id, (int) $row->user_id);
        $this->assertNotEmpty($row->reason);
        $this->assertNotNull($row->occurred_at);
    }

    public function test_a_refund_of_a_cash_tender_leaves_the_drawer_too(): void
    {
        // `refunded_amount` and the partially-refunded status are already how the
        // payment engine records money given back (3.3), and `countsTowardPaid()`
        // keeps such a tender in the takings at its net worth. The drawer has to
        // agree with that: a shift still counting the original tender would report
        // cash the shop handed back across the counter.
        $shiftId = $this->shiftId(['opening_balance' => '200000.0000']);

        $sale = $this->sell(4);

        DB::table('sale_payments')->where('sale_id', $sale['id'])->update([
            'refunded_amount' => '40000.0000',
            'status' => PaymentStatus::PartiallyRefunded->value,
        ]);

        $summary = $this->summaryOf($shiftId);
        $this->assertSame('100000.0000', $summary['cash_sales'], 'gross kept — the giveback is its own report line');
        $this->assertSame('40000.0000', $summary['cash_refunds']);
        $this->assertSame('260000.0000', $summary['expected_cash']);
        $this->assertSame('1', $summary['tender_count'], 'a partly refunded tender is still on this shift');
    }

    public function test_a_cash_refund_is_reported_apart_from_an_expense(): void
    {
        // Same direction, different fact. An expense is money the business spent; a
        // refund is money handed back against a sale. Fold one into the other and
        // the takings report claims the shop bought something it merely refunded.
        $shiftId = $this->shiftId(['opening_balance' => '200000.0000']);

        $this->movement($shiftId, [
            'type' => CashMovementType::Refund->value,
            'amount' => '40000.0000',
            'reason' => 'Refunded a cold coffee across the counter',
            'reference' => 'INV-REF-1',
        ])->assertCreated()
            ->assertJsonPath('data.group', 'refund')
            ->assertJsonPath('data.direction', 'out')
            ->assertJsonPath('data.reference', 'INV-REF-1');

        $summary = $this->summaryOf($shiftId);
        $this->assertSame('40000.0000', $summary['cash_refunds']);
        $this->assertSame('0.0000', $summary['cash_out']);
        $this->assertSame('160000.0000', $summary['expected_cash']);
    }

    public function test_a_cash_movement_needs_a_reason_and_a_positive_amount(): void
    {
        $shiftId = $this->shiftId();

        $this->movement($shiftId, ['reason' => ''])->assertStatus(422);
        $this->assertStringContainsString('reason', strtolower($this->errorMessage('reason')));

        $this->movement($shiftId, ['amount' => '0'])->assertStatus(422);
        $this->assertStringContainsString('more than zero', $this->errorMessage('amount'));

        $this->movement($shiftId, ['type' => 'bribe'])->assertStatus(422);

        // Money cannot have moved through a shift before it opened.
        $this->movement($shiftId, ['occurred_at' => now()->subDay()->toIso8601String()])->assertStatus(422);
        $this->assertStringContainsString('cannot have moved', $this->errorMessage('cash_movement.occurred_at'));

        // A direction is never an input: the type owns the sign, so a payload cannot
        // say "expense" and mean "in".
        $this->movement($shiftId, ['direction' => 'in'])->assertCreated();

        $this->assertSame(1, RegisterSession::find($shiftId)->movements()->count());
    }

    public function test_nothing_can_be_recorded_on_a_shift_that_is_not_open(): void
    {
        $shiftId = $this->shiftId();
        $this->close($shiftId, ['actual_balance' => '200000.0000'])->assertOk();

        $this->movement($shiftId)->assertStatus(422);
        $this->assertStringContainsString('is closed', $this->errorMessage('session'));
    }

    public function test_close_counts_the_drawer_and_computes_the_variance(): void
    {
        // 200.000 float + 100.000 of cash sales + 50.000 paid out = 250.000 expected.
        $shiftId = $this->shiftId(['opening_balance' => '200000.0000']);
        $this->sell(4);
        $this->movement($shiftId, ['amount' => '50000.0000'])->assertCreated();
        $this->assertSame('250000.0000', $this->expected($shiftId));

        $closed = $this->close($shiftId, ['actual_balance' => '240000.0000'])
            ->assertOk()
            ->assertJsonPath('data.status', RegisterSessionStatus::Closed->value)
            ->assertJsonPath('data.closing_balance', '250000.0000')
            ->assertJsonPath('data.actual_balance', '240000.0000')
            ->assertJsonPath('data.variance', '-10000.0000')
            ->assertJsonPath('data.summary.variance', '-10000.0000')
            ->json('data');

        $this->assertNotNull($closed['closed_at']);
        $this->assertSame($this->cashier->name, $closed['closed_by']);
        $this->assertSame($this->cashier->id, $closed['cashier_id']);

        // With no tolerance configured, any difference at all needs a signature.
        $this->assertSame('0.0000', $closed['variance_threshold']);
        $this->assertTrue($closed['requires_approval']);
        $this->assertTrue($closed['awaiting_approval']);
        $this->assertFalse($closed['is_approved']);

        // The register is free again: closing released the open key.
        $this->assertNull(RegisterSession::find($shiftId)->register_open_key);
        $this->getJson('/api/v1/register-sessions/current', $this->headers())
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_the_close_computes_everything_except_the_count(): void
    {
        $shiftId = $this->shiftId(['opening_balance' => '100000.0000']);

        // Expected, variance, threshold and the approval flags are all offered here
        // and none of them is read: a close that could send its own expected figure
        // is a close that can make any shortage disappear.
        $this->close($shiftId, [
            'actual_balance' => '100000.0000',
            'expected_cash' => '1.0000',
            'variance' => '0.0000',
            'requires_approval' => false,
            'is_approved' => true,
            'opening_balance' => '1.0000',
        ])->assertOk()
            ->assertJsonPath('data.expected_cash', '100000.0000')
            ->assertJsonPath('data.opening_balance', '100000.0000')
            ->assertJsonPath('data.variance', '0.0000')
            ->assertJsonPath('data.is_approved', false);
    }

    public function test_a_count_is_not_an_input_that_can_be_left_out_or_guessed(): void
    {
        $shiftId = $this->shiftId();

        $this->close($shiftId, [])->assertStatus(422);
        $this->assertStringContainsString('counted', $this->errorMessage('actual_balance'));

        $this->close($shiftId, ['actual_balance' => '-5'])->assertStatus(422);
        $this->assertStringContainsString('negative', $this->errorMessage('actual_balance'));

        // An empty drawer is a fact rather than a missing input.
        $this->close($shiftId, ['actual_balance' => '0'])->assertOk()
            ->assertJsonPath('data.actual_balance', '0.0000')
            ->assertJsonPath('data.variance', '-200000.0000');
    }

    public function test_a_shift_cannot_be_closed_while_a_sale_still_owes_money(): void
    {
        $shiftId = $this->shiftId(['opening_balance' => '200000.0000']);

        // Short tender: the engine records what was paid and leaves the ticket owing.
        $cartId = (int) $this->getJson('/api/v1/pos/cart', $this->headers())->assertOk()->json('data.id');
        $cart = $this->postJson("/api/v1/pos/cart/{$cartId}/items", [
            'product_id' => $this->coffee->id,
            'quantity' => '4',
        ], $this->headers())->assertCreated()->json('data.cart');

        $saleId = $this->postJson('/api/v1/sales', [
            'cart_id' => $cart['id'],
            'payments' => [['channel' => 'cash', 'amount' => '40000.0000']],
        ], $this->headers())->assertCreated()->json('data.id');

        $this->close($shiftId, ['actual_balance' => '240000.0000'])->assertStatus(422);
        $this->assertStringContainsString('still owing', $this->errorMessage('sales'));

        // Settling the ticket is the way out, not a second count.
        $this->postJson("/api/v1/sales/{$saleId}/complete", [
            'payments' => [['channel' => 'cash', 'amount' => '60000.0000']],
        ], $this->headers())->assertOk();

        $this->close($shiftId, ['actual_balance' => '300000.0000'])->assertOk()
            ->assertJsonPath('data.variance', '0.0000')
            ->assertJsonPath('data.requires_approval', false);
    }

    public function test_a_cancelled_sale_is_excluded_from_the_shift(): void
    {
        $shiftId = $this->shiftId(['opening_balance' => '200000.0000']);

        $sale = $this->sell(4);
        $this->assertSame('300000.0000', $this->expected($shiftId));

        $this->postJson("/api/v1/sales/{$sale['id']}/cancel", ['reason' => 'Customer left'], $this->headers())
            ->assertOk();

        $this->assertSame('200000.0000', $this->expected($shiftId), 'the goods came back, so the money leaves the sum');

        $summary = $this->summaryOf($shiftId);
        $this->assertSame('0', $summary['sales_count']);
        $this->assertSame('0', $summary['tender_count']);
    }

    public function test_the_change_handed_back_is_not_cash_the_drawer_kept(): void
    {
        // A 100.000 bill paid with a 150.000 note puts 150.000 in and 50.000
        // straight back out, so the drawer is 100.000 heavier. Counted at tendered
        // it would read 150.000, and every cashier in the shop would look 50.000
        // over-long by exactly the change they handed back.
        $shiftId = $this->shiftId(['opening_balance' => '200000.0000']);

        $sale = $this->sell(4, '150000.0000');

        $this->assertSame('100000.0000', $sale['grand_total']);
        $this->assertSame('150000.0000', $sale['payments'][0]['tendered']);
        $this->assertSame('50000.0000', $sale['payments'][0]['change']);
        $this->assertSame('300000.0000', $this->expected($shiftId));

        $this->close($shiftId, ['actual_balance' => '300000.0000'])->assertOk()
            ->assertJsonPath('data.variance', '0.0000')
            ->assertJsonPath('data.requires_approval', false);
    }

    public function test_a_non_cash_tender_does_not_move_expected_cash(): void
    {
        $shiftId = $this->shiftId(['opening_balance' => '200000.0000']);

        $cartId = (int) $this->getJson('/api/v1/pos/cart', $this->headers())->assertOk()->json('data.id');
        $cart = $this->postJson("/api/v1/pos/cart/{$cartId}/items", [
            'product_id' => $this->coffee->id,
            'quantity' => '8',
        ], $this->headers())->assertCreated()->json('data.cart');

        // 200.000 = 40.000 cash + 60.000 bank transfer + 100.000 on the customer's
        // account. Only the first of those is in the drawer.
        $this->postJson('/api/v1/sales', [
            'cart_id' => $cart['id'],
            'payments' => [
                ['channel' => 'cash', 'amount' => '40000.0000'],
                ['channel' => 'bank_transfer', 'amount' => '60000.0000', 'reference' => 'BB-991'],
            ],
        ], $this->headers())->assertCreated();

        $summary = $this->summaryOf($shiftId);
        $this->assertSame('40000.0000', $summary['cash_sales'], 'only the cash crossed the drawer');
        $this->assertSame('60000.0000', $summary['non_cash_sales'], 'the rest is reported, just not counted');
        $this->assertSame('240000.0000', $summary['expected_cash']);
    }

    public function test_a_tender_inherits_the_shift_its_ticket_was_billed_on(): void
    {
        // Membership is the stamped key, not a timestamp range. This is the case a
        // date-range sum gets wrong: the ticket was billed outside any shift and
        // paid while one was open, so a range would credit the current drawer with
        // money it never held.
        $cartId = (int) $this->getJson('/api/v1/pos/cart', $this->headers())->assertOk()->json('data.id');
        $cart = $this->postJson("/api/v1/pos/cart/{$cartId}/items", [
            'product_id' => $this->coffee->id,
            'quantity' => '4',
        ], $this->headers())->assertCreated()->json('data.cart');

        $saleId = $this->postJson('/api/v1/sales', [
            'cart_id' => $cart['id'],
            'payments' => [['channel' => 'cash', 'amount' => '40000.0000']],
        ], $this->headers())->assertCreated()->json('data');

        $this->assertNull($saleId['register_session_id'], 'billed with no drawer open');
        $saleId = $saleId['id'];

        // The next shift is open when the balance is settled.
        $shiftId = $this->shiftId(['opening_balance' => '50000.0000']);

        $this->postJson("/api/v1/sales/{$saleId}/complete", [
            'payments' => [['channel' => 'cash', 'amount' => '60000.0000']],
        ], $this->headers())->assertOk();

        $summary = $this->summaryOf($shiftId);
        $this->assertSame('0', $summary['tender_count'], 'the drawer took neither of those tenders');
        $this->assertSame('50000.0000', $summary['expected_cash']);
        $this->assertSame('100000.0000', $summary['unattributed_cash'], 'and the report says where that cash came from');
    }

    public function test_a_variance_inside_the_threshold_needs_no_signature(): void
    {
        $this->app->make(SettingsService::class)
            ->set('registers.variance_threshold', '20000', $this->shop()->id);

        $shiftId = $this->shiftId(['opening_balance' => '200000.0000']);

        // Exactly the threshold is inside it: the tolerance is what this shop decided
        // it could absorb without calling a supervisor to the counter.
        $this->close($shiftId, ['actual_balance' => '180000.0000'])->assertOk()
            ->assertJsonPath('data.variance', '-20000.0000')
            ->assertJsonPath('data.variance_threshold', '20000.0000')
            ->assertJsonPath('data.requires_approval', false)
            ->assertJsonPath('data.awaiting_approval', false);

        // One rupiah past it, in either direction, is not.
        $over = $this->shiftId(['opening_balance' => '200000.0000']);
        $this->close($over, ['actual_balance' => '179999.0000'])->assertOk()
            ->assertJsonPath('data.requires_approval', true)
            ->assertJsonPath('data.awaiting_approval', true);

        $long = $this->shiftId(['opening_balance' => '200000.0000']);
        $this->close($long, ['actual_balance' => '220001.0000'])->assertOk()
            ->assertJsonPath('data.variance', '20001.0000')
            ->assertJsonPath('data.requires_approval', true);
    }

    public function test_the_threshold_is_snapshotted_at_close(): void
    {
        $settings = $this->app->make(SettingsService::class);
        $settings->set('registers.variance_threshold', '10000', $this->shop()->id);

        $shiftId = $this->shiftId(['opening_balance' => '200000.0000']);
        $this->close($shiftId, ['actual_balance' => '185000.0000'])->assertOk()
            ->assertJsonPath('data.variance_threshold', '10000.0000')
            ->assertJsonPath('data.requires_approval', true);

        // Loosening the shop's tolerance afterwards must not turn an
        // approved-by-rule shift into an unexplained one, or a flagged one into a
        // quiet one.
        $settings->set('registers.variance_threshold', '99999', $this->shop()->id);

        $this->show($shiftId)->assertOk()
            ->assertJsonPath('data.variance_threshold', '10000.0000')
            ->assertJsonPath('data.requires_approval', true);
    }

    public function test_approval_accepts_a_variance_without_changing_it(): void
    {
        $shiftId = $this->shiftId(['opening_balance' => '200000.0000']);
        $this->close($shiftId, ['actual_balance' => '150000.0000'])->assertOk();

        $manager = $this->colleague(['register_sessions.view', 'register_sessions.approve']);

        $approved = $this->approve($shiftId, ['note' => 'Bank drop was 50.000 short; reported to the owner'], $manager)
            ->assertOk()
            ->assertJsonPath('data.is_approved', true)
            ->assertJsonPath('data.awaiting_approval', false)
            ->assertJsonPath('data.variance', '-50000.0000')
            ->assertJsonPath('data.approved_by', $manager->name)
            ->json('data');

        $this->assertNotNull($approved['approved_at']);

        // A signature says the shortage is accepted. It does not make the drawer
        // longer — if it could move the figure it would be indistinguishable from
        // the error it authorises.
        $row = RegisterSession::find($shiftId);
        $this->assertSame('-50000.0000', (string) $row->variance);
        $this->assertSame('150000.0000', (string) $row->actual_balance);
        $this->assertSame(RegisterSessionStatus::Closed->value, $row->status->value);

        $this->approve($shiftId, [], $manager)->assertStatus(422);
        $this->assertStringContainsString('Already approved', $this->errorMessage('approval'));
    }

    public function test_there_is_nothing_to_approve_on_a_shift_that_balanced(): void
    {
        $manager = $this->colleague(['register_sessions.approve']);
        $shiftId = $this->shiftId(['opening_balance' => '200000.0000']);
        $this->close($shiftId, ['actual_balance' => '200000.0000'])->assertOk();

        $this->approve($shiftId, [], $manager)->assertStatus(422);
        $this->assertStringContainsString('nothing to approve', $this->errorMessage('approval'));
    }

    public function test_an_open_shift_cannot_be_approved(): void
    {
        $manager = $this->colleague(['register_sessions.approve']);
        $shiftId = $this->shiftId();

        $this->approve($shiftId, [], $manager)->assertStatus(422);
        $this->assertStringContainsString('Close and count', $this->errorMessage('approval'));
    }

    public function test_a_cashier_cannot_approve_their_own_variance(): void
    {
        $shiftId = $this->shiftId(['opening_balance' => '200000.0000']);
        $this->close($shiftId, ['actual_balance' => '150000.0000'])->assertOk();

        // The seeded cashier set is view/open/process/close and deliberately not
        // approve: a signature on one's own shortage is not a control.
        $this->approve($shiftId)->assertForbidden();

        $this->assertFalse((bool) RegisterSession::find($shiftId)->is_approved);
    }

    public function test_unauthorized_reopen_of_a_closed_shift_is_refused(): void
    {
        $shiftId = $this->shiftId(['opening_balance' => '200000.0000']);
        $this->close($shiftId, ['actual_balance' => '150000.0000'])->assertOk();

        // The cashier worked the drawer and may not put it back open.
        $this->reopen($shiftId, ['reason' => 'Counted it wrong'])->assertForbidden();

        $row = RegisterSession::find($shiftId);
        $this->assertSame(RegisterSessionStatus::Closed->value, $row->status->value);
        $this->assertSame(0, (int) $row->reopen_count);
        $this->assertNull($row->register_open_key, 'a refusal must not have reclaimed the drawer');

        // Somebody in the shop with no shift permissions at all.
        $this->reopen($shiftId, ['reason' => 'Audit'], $this->colleague([]))->assertForbidden();

        // Somebody with the permission, in another company.
        $outsider = $this->authenticatedUser(['register_sessions.reopen']);
        $this->reopen($shiftId, ['reason' => 'Audit'], $outsider)->assertForbidden();

        $this->assertSame(0, (int) RegisterSession::find($shiftId)->reopen_count);
    }

    public function test_an_authorized_reopen_puts_the_shift_back_open(): void
    {
        $shiftId = $this->shiftId(['opening_balance' => '200000.0000']);
        $this->close($shiftId, ['actual_balance' => '150000.0000'])->assertOk();
        $this->approve($shiftId, ['note' => 'Shortage accepted'], $this->colleague(['register_sessions.approve']))
            ->assertOk();

        $manager = $this->colleague(['register_sessions.reopen', 'register_sessions.close']);

        $reopened = $this->reopen($shiftId, ['reason' => 'A tender was recorded against the wrong shift'], $manager)
            ->assertOk()
            ->assertJsonPath('data.status', RegisterSessionStatus::Open->value)
            ->assertJsonPath('data.reopen_count', 1)
            ->assertJsonPath('data.reopen_reason', 'A tender was recorded against the wrong shift')
            // The signature covered the count that was approved, and that count is
            // no longer the shift's last word.
            ->assertJsonPath('data.is_approved', false)
            ->assertJsonPath('data.approved_at', null)
            ->assertJsonPath('data.approved_by', null)
            ->json('data');

        $this->assertSame($manager->name, $reopened['reopened_by']);

        $row = RegisterSession::find($shiftId);
        $this->assertSame('register:'.$this->till()->id, $row->register_open_key);
        // The first count is not erased: it stays until the next close supersedes it,
        // so an audit can still see what was reported the first time.
        $this->assertSame('150000.0000', (string) $row->actual_balance);
        $this->assertSame('-50000.0000', (string) $row->variance);
        $this->assertNotNull($row->closed_at);

        // Back to work: money moves through it again and it can be counted again.
        // The float it opened with is still the shift's starting figure — reopening
        // continues a shift, it does not restart one.
        $this->movement($shiftId, [
            'type' => CashMovementType::OtherIncome->value,
            'amount' => '10000.0000',
            'reason' => 'Cash sale at the storeroom door',
        ])->assertCreated();

        $this->assertSame('210000.0000', $this->expected($shiftId));

        $this->close($shiftId, ['actual_balance' => '210000.0000'], $manager)->assertOk()
            ->assertJsonPath('data.variance', '0.0000')
            ->assertJsonPath('data.requires_approval', false)
            ->assertJsonPath('data.reopen_count', 1);
    }

    public function test_a_reopen_needs_a_reason_and_cannot_take_a_register_already_open(): void
    {
        $first = $this->shiftId(['opening_balance' => '200000.0000']);
        $this->close($first, ['actual_balance' => '200000.0000'])->assertOk();

        $manager = $this->colleague(['register_sessions.reopen']);

        $this->reopen($first, [], $manager)->assertStatus(422);
        $this->assertStringContainsString('reason', strtolower($this->errorMessage('reason')));

        // A second shift takes the drawer; the first cannot come back onto it.
        $second = $this->shiftId(['opening_balance' => '100000.0000']);

        $this->reopen($first, ['reason' => 'Recount needed'], $manager)->assertStatus(422);
        $this->assertStringContainsString('another shift open', $this->errorMessage('reason'));

        $this->assertSame(0, (int) RegisterSession::find($first)->reopen_count);
        $this->assertSame(RegisterSessionStatus::Open->value, RegisterSession::find($second)->status->value);
    }

    public function test_the_closing_report_shows_the_reconciliation_in_order(): void
    {
        $shiftId = $this->shiftId(['opening_balance' => '200000.0000']);
        $this->sell(4, '150000.0000');
        $this->movement($shiftId, [
            'type' => CashMovementType::CashInjection->value,
            'amount' => '30000.0000',
            'reason' => 'Extra float',
        ])->assertCreated();
        $this->movement($shiftId, ['amount' => '10000.0000'])->assertCreated();
        $this->movement($shiftId, [
            'type' => CashMovementType::Refund->value,
            'amount' => '5000.0000',
            'reason' => 'Refund across the counter',
        ])->assertCreated();

        // 200.000 + 100.000 + 30.000 − 5.000 − 10.000 = 315.000
        $this->assertSame('315000.0000', $this->expected($shiftId));
        $this->close($shiftId, ['actual_balance' => '300000.0000'])->assertOk();

        $report = $this->getJson("/api/v1/register-sessions/{$shiftId}/report", $this->headers())
            ->assertOk()
            ->json('data');

        $lines = collect($report['report'])->keyBy('key');

        $this->assertSame(
            ['opening_balance', 'cash_sales', 'cash_refunds', 'cash_in', 'cash_out',
                'expected_cash', 'actual_balance', 'variance'],
            $lines->pluck('key')->take(8)->all()
        );

        $this->assertSame('200000.0000', $lines['opening_balance']['value']);
        $this->assertSame('100000.0000', $lines['cash_sales']['value']);
        $this->assertSame('5000.0000', $lines['cash_refunds']['value']);
        $this->assertSame('30000.0000', $lines['cash_in']['value']);
        $this->assertSame('10000.0000', $lines['cash_out']['value']);
        $this->assertSame('315000.0000', $lines['expected_cash']['value']);
        $this->assertSame('300000.0000', $lines['actual_balance']['value']);
        $this->assertSame('-15000.0000', $lines['variance']['value']);

        $shift = $report['shift'];
        $this->assertMatchesRegularExpression('/^SHIFT-/', (string) $shift['number']);
        $this->assertSame($this->till()->code, $shift['register_code']);
        $this->assertSame($this->cashier->name, $shift['cashier']['name']);
        $this->assertSame('300000.0000', $shift['actual_balance']);
        $this->assertGreaterThanOrEqual(0, (int) $shift['duration_minutes']);

        // The movements behind the two report lines, each attributed to a person.
        $this->getJson("/api/v1/register-sessions/{$shiftId}/movements", $this->headers())
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.user.name', $this->cashier->name);

        $this->getJson("/api/v1/register-sessions/{$shiftId}/movements?group=in", $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'cash_injection');

        $this->getJson("/api/v1/register-sessions/{$shiftId}/movements?group=out", $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_the_movements_a_shift_shows_are_that_shifts_and_no_others(): void
    {
        $first = $this->shiftId();
        $this->movement($first, ['amount' => '20000.0000', 'reason' => 'First shift expense'])->assertCreated();
        $this->close($first, ['actual_balance' => '180000.0000'])->assertOk();

        $second = $this->shiftId(['opening_balance' => '100000.0000']);
        $this->movement($second, ['amount' => '5000.0000', 'reason' => 'Second shift expense'])->assertCreated();

        $this->getJson("/api/v1/register-sessions/{$second}/movements", $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reason', 'Second shift expense')
            ->assertJsonPath('data.0.shift_number', RegisterSession::find($second)->number);

        // And the shift response carries its own.
        $this->show($second)->assertOk()->assertJsonCount(1, 'data.movements');
    }

    public function test_cash_taken_with_no_shift_open_is_reported_beside_the_shift(): void
    {
        // Money in the drawer that no shift owns is a gap a shop needs to see rather
        // than infer: without it a count reads long and the difference gets blamed on
        // the cashier who never had a shift to reconcile it against.
        $this->sell(4);

        $shiftId = $this->shiftId([
            'opening_balance' => '200000.0000',
            // Opened before the sale above, so the loose cash is inside this
            // shift's own lifetime.
            'opened_at' => now()->subHour()->toIso8601String(),
        ]);

        $summary = $this->summaryOf($shiftId);
        $this->assertSame('100000.0000', $summary['unattributed_cash']);
        $this->assertSame('1', $summary['unattributed_count']);
        $this->assertSame('200000.0000', $summary['expected_cash'], 'and still not folded into this shift');

        // A shift that says nothing about it would be the same drawer, unexplained.
        $this->close($shiftId, ['actual_balance' => '300000.0000'])->assertOk()
            ->assertJsonPath('data.summary.unattributed_cash', '100000.0000');
    }

    public function test_the_current_endpoint_answers_for_the_open_shift(): void
    {
        $this->getJson('/api/v1/register-sessions/current', $this->headers())
            ->assertOk()
            // Null rather than 404: a till that cannot tell "nothing open" apart
            // from "not allowed" would show the wrong screen.
            ->assertJsonPath('data', null);

        $shiftId = $this->shiftId();

        $this->getJson('/api/v1/register-sessions/current', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.id', $shiftId)
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.expected_cash', '200000.0000');

        $this->getJson('/api/v1/register-sessions/current?register_id=999999', $this->headers())
            ->assertStatus(422);

        // A drawer list that names the shift each one has open, so a till can decide
        // what to show in one round trip.
        $second = $this->secondTill();
        $otherShift = $this->shiftId(['opening_balance' => '75000.0000'], null, $second->id);

        $registers = $this->getJson('/api/v1/register-sessions/registers', $this->headers())
            ->assertOk()
            ->json('data');

        $byId = collect($registers)->keyBy('id');
        $this->assertSame($shiftId, $byId[$this->till()->id]['open_session']['id']);
        $this->assertSame($otherShift, $byId[$second->id]['open_session']['id']);
    }

    public function test_shifts_can_be_listed_and_filtered_by_what_needs_signing(): void
    {
        $short = $this->shiftId(['opening_balance' => '200000.0000']);
        $this->close($short, ['actual_balance' => '150000.0000'])->assertOk();

        $balanced = $this->shiftId(['opening_balance' => '100000.0000']);
        $this->close($balanced, ['actual_balance' => '100000.0000'])->assertOk();

        $working = $this->shiftId(['opening_balance' => '50000.0000'], null, $this->secondTill()->id);

        $all = $this->getJson('/api/v1/register-sessions', $this->headers())->assertOk();
        $this->assertSame(3, $all->json('meta.total'));
        $this->assertSame($working, $all->json('data.0.id'), 'newest shift first');

        $this->getJson('/api/v1/register-sessions?status=open', $this->headers())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $working);

        $awaiting = $this->getJson('/api/v1/register-sessions?awaiting_approval=1', $this->headers())->assertOk();
        $this->assertSame([$short], array_column($awaiting->json('data'), 'id'));

        $this->getJson('/api/v1/register-sessions?register_id='.$this->till()->id, $this->headers())
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        // A list row carries the counted figures and says it has no live summary,
        // rather than answering null for a figure nobody computed.
        $row = $all->json('data.0');
        $this->assertNull($row['summary']);
        $this->assertNull($row['expected_cash']);
        $this->assertSame('150000.0000', $all->json('data.2.actual_balance'));
        $this->assertSame('-50000.0000', $all->json('data.2.variance'));
        $this->assertSame($this->till()->code, $all->json('data.2.register_code'));
    }

    public function test_a_user_without_permission_cannot_open_a_register(): void
    {
        $broke = $this->colleague([]);

        $this->postJson('/api/v1/register-sessions', ['opening_balance' => '1000'], $this->headers($broke))
            ->assertForbidden();

        $this->assertSame(0, RegisterSession::count());
    }

    public function test_a_shift_cannot_be_read_or_worked_across_companies(): void
    {
        $shiftId = $this->shiftId();

        $outsider = $this->authenticatedUser([
            'register_sessions.view', 'register_sessions.open', 'register_sessions.process',
            'register_sessions.close', 'register_sessions.approve', 'register_sessions.reopen',
        ]);

        $this->show($shiftId, $outsider)->assertForbidden();
        $this->close($shiftId, ['actual_balance' => '1000'], $outsider)->assertForbidden();
        $this->approve($shiftId, [], $outsider)->assertForbidden();
        $this->reopen($shiftId, ['reason' => 'Mine now'], $outsider)->assertForbidden();
        $this->movement($shiftId, [], $outsider)->assertForbidden();
        $this->getJson("/api/v1/register-sessions/{$shiftId}/report", $this->headers($outsider))->assertForbidden();
        $this->getJson("/api/v1/register-sessions/{$shiftId}/movements", $this->headers($outsider))->assertForbidden();

        $this->getJson('/api/v1/register-sessions', $this->headers($outsider))
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        // The outsider has a register of their own, so `current` is asked about ours.
        $this->getJson('/api/v1/register-sessions/current?register_id='.$this->till()->id, $this->headers($outsider))
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->getJson('/api/v1/register-sessions/registers', $this->headers($outsider))
            ->assertOk()
            ->assertJsonPath('data.0.open_session', null);

        // And their own drawer opens fine on their own register.
        $outsider->registers()->detach($outsider->registers()->first()?->id);
        $this->openRegister([], $outsider)->assertStatus(422);
    }

    public function test_a_sale_cannot_be_taken_when_the_shop_requires_an_open_shift(): void
    {
        $this->app->make(SettingsService::class)
            ->set('registers.require_open_shift', true, $this->shop()->id);

        $cartId = (int) $this->getJson('/api/v1/pos/cart', $this->headers())->assertOk()->json('data.id');
        $cart = $this->postJson("/api/v1/pos/cart/{$cartId}/items", [
            'product_id' => $this->coffee->id,
            'quantity' => '2',
        ], $this->headers())->assertCreated()->json('data.cart');

        $this->last = $this->postJson('/api/v1/sales', [
            'cart_id' => $cart['id'],
            'payments' => [['channel' => 'cash', 'amount' => '50000.0000']],
        ], $this->headers())->assertStatus(422);

        $this->assertStringContainsString('not open', $this->errorMessage('register'));

        // Open the drawer and the same ticket goes through, stamped onto the shift.
        $shiftId = $this->shiftId();

        $sale = $this->postJson('/api/v1/sales', [
            'cart_id' => $cart['id'],
            'payments' => [['channel' => 'cash', 'amount' => '50000.0000']],
        ], $this->headers())->assertCreated()->json('data');

        $this->assertSame($shiftId, $sale['register_session_id']);
        $this->assertSame('250000.0000', $this->expected($shiftId));
    }

    public function test_a_shift_cannot_be_edited_or_deleted_through_the_api(): void
    {
        $shiftId = $this->shiftId();

        // No route at all. Counted money is corrected by reopening and counting
        // again, which is the only reason a variance means anything.
        $this->putJson("/api/v1/register-sessions/{$shiftId}", ['opening_balance' => '1'], $this->headers())
            ->assertStatus(405);
        $this->patchJson("/api/v1/register-sessions/{$shiftId}", ['opening_balance' => '1'], $this->headers())
            ->assertStatus(405);
        $this->deleteJson("/api/v1/register-sessions/{$shiftId}", [], $this->headers())->assertStatus(405);

        $this->assertSame(1, RegisterSession::count());
    }
}
