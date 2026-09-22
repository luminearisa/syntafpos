<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3.5 — the money that goes back: sales returns and refunds.
 *
 * The subphase is deliberately split into two documents rather than one, because
 * the two facts are separate. A return is *goods* coming back and is what moves
 * stock; a refund is *money* going out and is what writes a tender down. A shop
 * exchanges a shirt without refunding anything, and a shop refunds a deposit
 * without receiving goods — folding them into one table would make "how much
 * stock came back" and "how much money left" the same column, which is exactly
 * the confusion the ledgers exist to prevent.
 *
 * A return stores its own copy of the sale line it reverses — price, discount,
 * tax and the cost basis the outgoing movement used — so Phase 4 can reverse
 * revenue, tax, COGS and inventory from the return alone. A refund stores the
 * allocations that say which tender each rupiah came off, which is what makes a
 * multi-payment refund auditable rather than a single figure split nobody can
 * reproduce.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            // The stockroom the goods go back into, read off the original sale.
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            // The document this reverses. Never nullable: a return that does not
            // point at a real sale is not a return, it is an adjustment.
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignId('register_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('register_session_id')->nullable()->constrained('register_sessions')->nullOnDelete();
            // Customer snapshot for the return slip, taken off the sale.
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();

            // From the numbering engine's `return` type: RET-2026-000001.
            $table->string('number', 64);
            $table->date('return_date');
            // draft | completed | cancelled — see SaleReturnStatus. A return is
            // completed in the same transaction that writes it, so draft only
            // exists inside that transaction.
            $table->string('status', 24)->default('draft');
            // Why the customer brought it back. Required by the service.
            $table->string('reason', 500);
            $table->timestamp('posted_at')->nullable();

            // Money returned, derived from the lines by the return engine. The
            // cost figures are the Phase 4 seam: COGS reversal is the sum of
            // total_cost across the lines.
            $table->decimal('subtotal', 20, 4)->default(0);
            $table->decimal('discount_total', 20, 4)->default(0);
            $table->decimal('tax_total', 20, 4)->default(0);
            $table->decimal('grand_total', 20, 4)->default(0);
            $table->decimal('cost_total', 20, 4)->default(0);
            $table->string('currency', 8)->default('IDR');

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'sale_id']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'return_date']);
            $table->index('sale_id');
        });

        Schema::create('sale_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_return_id')->constrained('sale_returns')->cascadeOnDelete();
            // The line being reversed, kept nullable and nullOnDelete so the
            // return survives a catalogue change; everything the return needs is
            // copied below, exactly as a sale line copies a product.
            $table->foreignId('sale_item_id')->nullable()->constrained('sale_items')->nullOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->foreignId('tax_id')->nullable()->constrained()->nullOnDelete();

            $table->string('product_name');
            $table->string('product_sku', 128);
            $table->string('variant_name')->nullable();
            $table->string('unit_code', 32)->nullable();

            $table->decimal('quantity', 20, 6);
            $table->decimal('unit_price', 20, 4);
            $table->decimal('discount', 20, 4)->default(0);
            $table->string('discount_type', 12)->default('amount');
            $table->decimal('discount_amount', 20, 4)->default(0);
            $table->decimal('tax_rate', 10, 4)->default(0);
            // exclusive | inclusive
            $table->string('tax_mode', 16)->default('exclusive');
            $table->decimal('tax_amount', 20, 4)->default(0);
            $table->decimal('line_subtotal', 20, 4)->default(0);
            $table->decimal('line_total', 20, 4)->default(0);

            // The weighted average the goods were valued at when they left, read
            // off the original sale movement. Phase 4 reverses COGS from this.
            $table->decimal('unit_cost', 20, 4)->default(0);
            $table->decimal('total_cost', 20, 4)->default(0);

            // Whether this line goes back on the shelf. False for a damaged item
            // the customer returned but the shop will not resell.
            $table->boolean('restock')->default(true);
            $table->string('reason', 500)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('sale_return_id');
            $table->index(['company_id', 'product_id']);
            $table->index('sale_item_id');
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            // Which return prompted it, when one did. Null for a goodwill refund
            // with no goods coming back.
            $table->foreignId('sale_return_id')->nullable()->constrained('sale_returns')->nullOnDelete();
            $table->foreignId('register_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('register_session_id')->nullable()->constrained('register_sessions')->nullOnDelete();

            // From the numbering engine's `refund` type: RFD-2026-000001.
            $table->string('number', 64);
            // cash | original_payment | manual | gateway — see RefundMethod.
            $table->string('method', 24);
            // requested | approved | processing | completed | failed | rejected
            $table->string('status', 24)->default('requested');
            $table->decimal('amount', 20, 4);
            $table->string('currency', 8)->default('IDR');
            $table->string('reason', 500);
            // A gateway reversal id, or a bank slip for a manual refund. The
            // Phase 3.8 seam; nothing here talks to a provider.
            $table->string('external_reference', 128)->nullable();

            // The approval rule this refund was judged against, snapshotted so a
            // later change to the shop's threshold cannot rewrite the decision.
            $table->decimal('approval_threshold', 20, 4)->default(0);
            $table->boolean('approval_required')->default(false);

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->string('failure_reason', 500)->nullable();

            $table->json('metadata')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'sale_id']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'requested_at']);
            $table->index('sale_id');
        });

        Schema::create('refund_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('refund_id')->constrained('refunds')->cascadeOnDelete();
            // The tender the money came off. Nullable and nullOnDelete only as a
            // safety net: a payment is never deleted, so in practice this always
            // resolves.
            $table->foreignId('sale_payment_id')->nullable()->constrained('sale_payments')->nullOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 20, 4);
            $table->string('currency', 8)->default('IDR');
            $table->timestamps();

            $table->index('refund_id');
            $table->index('sale_payment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_allocations');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('sale_return_items');
        Schema::dropIfExists('sale_returns');
    }
};
