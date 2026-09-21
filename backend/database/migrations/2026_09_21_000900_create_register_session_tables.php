<?php

use App\Enums\RegisterSessionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3.4 — the cash register's operational engine.
 *
 * Two tables: `register_sessions`, one row per shift a drawer was worked, and
 * `cash_movements`, the money that entered or left a drawer without being a
 * tender. The sales and payment tables each gain a `register_session_id`, which
 * is where the reconciliation gets its figures from.
 *
 * Three decisions in here are worth naming, because they are the difference
 * between a shift report that balances and one that is merely plausible.
 *
 * **The session is stamped on the transaction, not summed from a date range.**
 * `WHERE created_at BETWEEN open AND close` is the tempting version, and it is
 * wrong twice over: a sale left open across a close (payment taken after the
 * drawer was counted) either falls into both shifts or neither, and a reopened
 * shift would silently change what yesterday's report said. A foreign key
 * decides membership once, at the moment the money moved, and reopening a shift
 * keeps the rows it already had.
 *
 * **Expected cash is computed, never stored as an input.** The columns named
 * `closing_balance`, `actual_balance` and `variance` are written once, by the
 * close, from figures the engine already owns. A client that could post an
 * expected figure would be a client that could make any variance disappear.
 * `actual_balance` is the only counted number a human types here, and `variance`
 * is always `actual − expected`, so a shortage is negative and an overage is
 * positive — one sign convention for the whole app, including the reports.
 *
 * **One open shift per register is a database constraint, not a check.**
 * `register_open_key` holds the register's identity while the session is open and
 * NULL once it is closed. A unique index allows unlimited NULLs on every driver
 * this app runs on, so it enforces exactly one open session per register without
 * needing a filtered index (which MySQL does not have) or a read-then-insert race
 * (which two cashiers tapping Open at the same moment will win). Reopening puts
 * the key back, and collides again on purpose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('register_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // The outlet the drawer physically sits in. Taken off the register at
            // open and frozen: a register moved to another branch next month must
            // not relocate last month's takings.
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('register_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            // The person the shift is *on*, which is not necessarily the person
            // who pressed Open: `opened_by`/`closed_by` are the actors, this is the
            // accountable one, and a supervisor opening for a new cashier keeps the
            // takings attributed to the cashier who worked the drawer.
            $table->foreignId('cashier_id')->constrained('users')->cascadeOnDelete();

            // From the numbering engine's `shift` type: SHIFT-2026-000001. The
            // closing report is a document a manager signs, so it is numbered.
            $table->string('number', 64);

            // open | closed
            $table->string('status', 16)->default(RegisterSessionStatus::Open->value);
            // The register's identity while this session is the open one, NULL
            // otherwise. Unique index below.
            $table->string('register_open_key', 64)->nullable();

            $table->decimal('opening_balance', 20, 4)->default(0);
            $table->timestamp('opened_at');
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            // Expected cash at the moment of closing, computed by the engine.
            $table->decimal('closing_balance', 20, 4)->nullable();
            // What the cashier counted. Nullable separately from the figures above
            // so "counted nothing" and "counted zero" are not the same row.
            $table->decimal('actual_balance', 20, 4)->nullable();
            // actual − expected; negative is a shortage.
            $table->decimal('variance', 20, 4)->nullable();
            // The threshold this variance was judged against, snapshotted from
            // settings at close: a manager who later loosens it must not turn an
            // approved-by-rule shift into an unexplained one.
            $table->decimal('variance_threshold', 20, 4)->nullable();
            // |variance| > threshold, so a supervisor has to sign it off.
            $table->boolean('requires_approval')->default(false);
            $table->boolean('is_approved')->default(false);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('approval_note', 500)->nullable();

            // Reopening is authorised, not free: every reopen is attributed and
            // the reason is mandatory in the API, so a shift does not quietly
            // become editable again.
            $table->unsignedSmallInteger('reopen_count')->default(0);
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->string('reopen_reason', 500)->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->unique('register_open_key');
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'register_id', 'opened_at']);
            $table->index(['company_id', 'cashier_id', 'opened_at']);
            $table->index(['company_id', 'requires_approval', 'is_approved']);
        });

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('register_id')->nullable()->constrained()->nullOnDelete();
            // Cascade, not nullOnDelete: a movement belongs to a shift's arithmetic
            // and has no meaning outside it. Deleting the shift is a controlled
            // operation that must not leave orphan money behind.
            $table->foreignId('register_session_id')->constrained()->cascadeOnDelete();
            // Who moved the money — the work order's `User`, kept apart from the
            // shift's cashier because a supervisor hands float to a drawer.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // cash_injection | other_income | expense | withdrawal | petty_cash |
            // refund. Direction and report line both follow from this, so neither
            // is stored: see CashMovementType.
            $table->string('type', 32);
            // Always positive. The sign comes from the type, which means a
            // negative-amount row is impossible rather than merely rejected.
            $table->decimal('amount', 20, 4);
            $table->string('currency', 3)->default('IDR');
            $table->string('reason', 500);
            $table->string('reference', 128)->nullable();
            // When the money actually moved, which the cashier may set apart from
            // when it was keyed: a float note found an hour later belongs to the
            // hour it was found in, but a slip written at 09:00 records 09:00.
            $table->timestamp('occurred_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'register_session_id']);
            $table->index(['company_id', 'type', 'occurred_at']);
            $table->index(['register_id', 'occurred_at']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('register_session_id')
                ->nullable()
                ->after('register_id')
                ->constrained('register_sessions')
                ->nullOnDelete();
            $table->index(['company_id', 'register_session_id']);
        });

        Schema::table('sale_payments', function (Blueprint $table) {
            // nullOnDelete: the money this tender represents is recorded whether
            // or not the shift behind it survives, and a shift row is data an
            // owner may archive. The shift report reads the payments, not the
            // other way round.
            $table->foreignId('register_session_id')
                ->nullable()
                ->after('register_id')
                ->constrained('register_sessions')
                ->nullOnDelete();
            $table->index(['company_id', 'register_session_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'register_session_id', 'channel']);
        });
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'register_session_id']);
        });

        // SQLite drops the foreign key with the column; MySQL needs the index
        // gone first, which the drops above handle.
        Schema::table('sale_payments', fn () => DB::statement('ALTER TABLE sale_payments DROP COLUMN register_session_id'));
        Schema::table('sales', fn () => DB::statement('ALTER TABLE sales DROP COLUMN register_session_id'));

        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('register_sessions');
    }
};
