<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A sale is the transaction a cart becomes. Unlike the cart, it is a
        // document: it carries a number from the Phase 1 sequence engine, it is
        // the reference a stock movement points at, and every figure on it is a
        // snapshot taken at checkout so a later master-data edit cannot rewrite
        // history.
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            // The warehouse stock actually left. Kept even though the balance
            // rows already know it, because a movement's reference must be
            // readable on its own.
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('register_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cashier_id')->nullable()->constrained('users')->nullOnDelete();
            // Provenance: which cart this sale came out of, and the note a
            // recalled park carried. Null for a sale raised outside the till.
            $table->foreignId('pos_cart_id')->nullable()->constrained()->nullOnDelete();

            // From the numbering engine's `invoice` type: INV-2026-000001.
            $table->string('number', 64);
            $table->date('date');
            // draft | pending_payment | partially_paid | paid | completed | cancelled
            $table->string('status', 24)->default('draft');
            // Stock has left (or would have) only once posted; cancelling a
            // posted sale therefore has to put it back, and an unposted one
            // simply disappears.
            $table->timestamp('stock_posted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason')->nullable();

            // Derived by the shared money engine; a client-sent total is never
            // stored, same rule as the cart and the purchasing documents.
            $table->decimal('subtotal', 20, 4)->default(0);
            $table->decimal('item_discount_total', 20, 4)->default(0);
            $table->decimal('discount_input', 20, 4)->default(0);
            // amount | percent — how discount_input is read.
            $table->string('discount_type', 12)->default('amount');
            $table->decimal('discount_total', 20, 4)->default(0);
            $table->decimal('tax_total', 20, 4)->default(0);
            // Portion of tax_total already inside the quoted line prices.
            $table->decimal('tax_included_total', 20, 4)->default(0);
            $table->decimal('other_charges', 20, 4)->default(0);
            $table->decimal('rounding', 20, 4)->default(0);
            $table->decimal('grand_total', 20, 4)->default(0);
            // Payment foundation only: what has been collected so far and the
            // resulting change owed to the customer. Gateways arrive in 3.8.
            $table->decimal('paid_total', 20, 4)->default(0);
            $table->decimal('change_due', 20, 4)->default(0);
            $table->string('currency', 8)->default('IDR');

            // Customer snapshot, so an invoice printed years from now still
            // names the person who bought, even if their record is renamed,
            // moved to another group or deleted.
            $table->string('customer_name')->nullable();
            $table->string('customer_code', 128)->nullable();
            $table->string('customer_phone', 64)->nullable();
            $table->string('customer_email', 255)->nullable();
            $table->text('customer_address')->nullable();

            // Outlet snapshot for the same reason, read off the branch at
            // checkout; the invoice header must match the store that served.
            $table->string('branch_name')->nullable();
            $table->string('branch_address')->nullable();
            $table->string('branch_phone', 64)->nullable();
            $table->string('register_code', 64)->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // The company-scoped unique index is the last line of defence
            // against a duplicated number: the sequence engine increments
            // atomically, and a collision would surface here rather than reach
            // two receipts.
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'date']);
            $table->index(['company_id', 'register_id', 'date']);
            $table->index(['company_id', 'customer_id']);
            $table->index('pos_cart_id');
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // cascadeOnDelete on a transaction line would let a catalogue
            // deletion erase a receipt's history, so these keep the id only as
            // a lookup hint and survive their product.
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->foreignId('tax_id')->nullable()->constrained()->nullOnDelete();

            // Identity snapshot copied from the cart line, then frozen. This is
            // the block the work order calls SNAPSHOT: name, SKU, price, tax,
            // discount and unit all live here, not in a join back to the
            // catalogue.
            $table->string('product_name');
            $table->string('product_sku', 128);
            $table->string('barcode', 128)->nullable();
            $table->string('variant_name')->nullable();
            $table->string('unit_code', 32)->nullable();

            $table->decimal('quantity', 20, 6);
            $table->decimal('unit_price', 20, 4);
            $table->string('price_source', 24)->default('product');
            $table->decimal('discount', 20, 4)->default(0);
            $table->string('discount_type', 12)->default('amount');
            $table->decimal('tax_rate', 10, 4)->default(0);
            // exclusive | inclusive
            $table->string('tax_mode', 16)->default('exclusive');

            $table->decimal('line_subtotal', 20, 4)->default(0);
            $table->decimal('discount_amount', 20, 4)->default(0);
            $table->decimal('tax_amount', 20, 4)->default(0);
            $table->decimal('line_total', 20, 4)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('sale_id');
            $table->index(['company_id', 'product_id']);
        });

        // Payment foundation: one row per tender taken, so the total collected
        // and the balance owed are facts on the transaction rather than a
        // client-sent figure. Phase 3.8 adds gateway capture against these;
        // nothing here talks to a provider.
        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('register_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('number', 64);
            // cash | card | debit | credit | wallet | transfer | other
            $table->string('method', 24)->default('cash');
            $table->decimal('amount', 20, 4);
            // tendered - amount for cash, so the drawer reconciliation in a
            // later phase can see the note breakdown rather than just the net.
            $table->decimal('tendered', 20, 4)->default(0);
            $table->decimal('change', 20, 4)->default(0);
            // A gateway reference; null until 3.8.
            $table->string('reference', 128)->nullable();
            // pending | completed | voided — the payment's own state, kept apart
            // from the sale's so a failed capture does not post a sale.
            $table->string('status', 16)->default('completed');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index('sale_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_payments');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
    }
};
