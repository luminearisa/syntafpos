<?php

use App\Enums\PaymentChannel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3.3 — the payment engine's two tables.
 *
 * `payment_methods` is the configurable half: what a shop calls a tender, whether
 * the cashier has to type a reference for it, the order the till lists them in.
 * `sale_payments` gains the columns the work order names — payment_method_id,
 * currency, reference, paid_at, metadata — and its `method` column becomes
 * `channel`, because what it stores from here on is the kind of money rather
 * than a row a shop can rename.
 *
 * The point of splitting it that way is history. A payment method is master data:
 * it can be renamed, switched off or deleted. A payment is a financial record. So
 * a payment points at its method for convenience and *copies* what it needs out
 * of it — channel and name — exactly as a sale line copies its product. Deleting
 * "QRIS (old campaign)" next year must not make ten thousand settled tenders
 * unreadable, and a method's own settings changing must not retroactively decide
 * whether an old payment needed a reference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            // The kind of money this is: cash | bank_transfer | debit |
            // credit_card | qris | e_wallet | virtual_account |
            // customer_credit | other. Behaviour follows the channel, so this is
            // a closed set rather than free text.
            $table->string('channel', 32);
            // Which PaymentProviderInterface implementation captures it. Null
            // means the till records the tender itself, which is all 3.3 has;
            // Subphase 3.8 sets this to `midtrans` and registers the class.
            $table->string('provider', 64)->nullable();
            $table->string('icon', 64)->nullable();
            $table->string('description', 500)->nullable();

            // The behaviour flags a cashier actually feels: must a reference be
            // typed, is this the till's default button, may a payment on this
            // method be recorded before the money is confirmed.
            $table->boolean('requires_reference')->default(false);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            // Channel-specific settings that are not behaviour: a wallet's brand,
            // a bank's account number to print on the invoice.
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active']);
        });

        Schema::table('sale_payments', function (Blueprint $table) {
            // nullOnDelete: the method is master data and may be retired, the
            // payment may not. The two snapshot columns below are what keep the
            // old tender readable once it has been.
            $table->foreignId('payment_method_id')
                ->nullable()
                ->after('received_by')
                ->constrained('payment_methods')
                ->nullOnDelete();
            // Added nullable and tightened below once the existing rows have
            // been backfilled: SQLite refuses a NOT NULL column with no default
            // onto a table that already holds data, and these rows do exist in
            // any shop that has rung up a sale.
            $table->string('channel', 32)->nullable()->after('payment_method_id');
            $table->string('method_name')->nullable()->after('channel');
            $table->string('currency', 3)->default('IDR')->after('amount');
            $table->decimal('refunded_amount', 20, 4)->default(0)->after('change');
            $table->timestamp('paid_at')->nullable()->after('status');
            $table->json('metadata')->nullable()->after('paid_at');
        });

        // Status is renamed, not added to: `completed` was 3.2's word for "the
        // money is in", which the work order calls Paid, and `voided` for
        // "withdrawn", which it calls Cancelled. Two names for one state across
        // an enum, a screen and a receipt is how a support conversation ends with
        // someone reading the wrong column.
        DB::table('sale_payments')->where('status', 'completed')->update(['status' => 'paid']);
        DB::table('sale_payments')->where('status', 'voided')->update(['status' => 'cancelled']);

        Schema::table('sale_payments', function (Blueprint $table) {
            $table->string('status', 24)->default('pending')->change();
        });

        // 3.2 stored the channel-ish value in `method`. It is moved into the new
        // column, with the two names that were ambiguous mapped onto the channel
        // they were being used for, and the old column dropped: leaving both
        // would mean a payment could disagree with itself.
        foreach ($this->legacyChannels() as $legacy => $channel) {
            DB::table('sale_payments')->where('method', $legacy)->update(['channel' => $channel]);
        }

        DB::table('sale_payments')
            ->whereNull('channel')
            ->orWhere('channel', '')
            ->update(['channel' => PaymentChannel::Cash->value]);

        // The name a payment was taken under, so a document still reads correctly
        // after the method row behind it is renamed or retired. 3.2 had no name of
        // its own — the enum label was doing the job — so the backfill writes the
        // label each channel carries, which is exactly what those receipts showed.
        foreach (PaymentChannel::cases() as $channel) {
            DB::table('sale_payments')
                ->where('channel', $channel->value)
                ->where(fn ($q) => $q->whereNull('method_name')->orWhere('method_name', ''))
                ->update(['method_name' => $channel->label()]);
        }

        // A payment with a paid date is when the money arrived; 3.2 recorded every
        // tender as settled the moment it was written, so `created_at` is the
        // honest answer for its rows and `paid_at` would otherwise print a dash.
        DB::table('sale_payments')
            ->where('status', 'paid')
            ->whereNull('paid_at')
            ->update(['paid_at' => DB::raw('created_at')]);

        Schema::table('sale_payments', function (Blueprint $table) {
            $table->dropColumn('method');
        });

        // Both snapshot columns are required on every payment from here: a tender
        // that does not say what kind of money it was cannot be reconciled, and
        // the copy is now the only place the name lives. Only after the backfills
        // above can the columns be tightened, and SQLite needs to rebuild the
        // table to do it, so foreign keys are deferred for the duration.
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->string('channel', 32)->nullable(false)->change();
            $table->string('method_name')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->string('method', 24)->default('cash')->after('received_by');
        });

        foreach (array_flip($this->legacyChannels()) as $channel => $legacy) {
            DB::table('sale_payments')->where('channel', $channel)->update(['method' => $legacy]);
        }

        DB::table('sale_payments')->where('status', 'paid')->update(['status' => 'completed']);
        DB::table('sale_payments')->where('status', 'cancelled')->update(['status' => 'voided']);
        DB::table('sale_payments')
            ->whereIn('status', ['failed', 'refunded', 'partially_refunded'])
            ->update(['status' => 'pending']);

        Schema::table('sale_payments', function (Blueprint $table) {
            $table->dropColumn([
                'payment_method_id', 'channel', 'method_name', 'currency',
                'refunded_amount', 'paid_at', 'metadata', 'method',
            ]);
            $table->string('status', 16)->default('completed')->change();
        });

        Schema::dropIfExists('payment_methods');
    }

    /**
     * 3.2's `method` values mapped onto channels.
     *
     * `card` and `credit` both mean the reader: they were the two spellings a
     * cashier could pick for one action, and the card channel is what both were.
     *
     * @return array<string, string>
     */
    private function legacyChannels(): array
    {
        return [
            'cash' => PaymentChannel::Cash->value,
            'card' => PaymentChannel::CreditCard->value,
            'credit' => PaymentChannel::CreditCard->value,
            'debit' => PaymentChannel::Debit->value,
            'wallet' => PaymentChannel::EWallet->value,
            'transfer' => PaymentChannel::BankTransfer->value,
            'other' => PaymentChannel::Other->value,
        ];
    }
};
