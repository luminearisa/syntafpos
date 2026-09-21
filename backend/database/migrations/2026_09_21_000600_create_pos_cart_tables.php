<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A point-of-sale cart is a draft transaction, not a document: it never
        // touches stock, revenue or the ledger. Nothing here writes to
        // stock_balances or stock_movements; those move only when a sale is
        // completed in a later subphase.
        Schema::create('pos_carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('register_id')->nullable()->constrained()->nullOnDelete();
            // The cashier the cart currently belongs to. A recalled park is
            // reassigned, so this is ownership of the working copy rather than
            // an audit of who first opened it.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            // Recall code, assigned only when the cart is put on hold. Active
            // carts are found by (company, register, cashier) and need no number,
            // so the document sequence is never burned on a throwaway cart.
            $table->string('number', 32)->nullable();
            // active | held
            $table->string('status', 16)->default('active');
            $table->string('label')->nullable();
            $table->timestamp('held_at')->nullable();

            // Money columns are always derived by PosCartCalculationService;
            // a client-sent total is never stored. The two *_input columns are
            // the raw entry, kept separate so recomputing a percent discount
            // against changed lines never re-reads an already-resolved amount.
            $table->decimal('subtotal', 20, 4)->default(0);
            $table->decimal('item_discount_total', 20, 4)->default(0);
            $table->decimal('discount_input', 20, 4)->default(0);
            // amount | percent — how discount_input is interpreted.
            $table->string('discount_type', 12)->default('amount');
            $table->decimal('discount_total', 20, 4)->default(0);
            $table->decimal('tax_total', 20, 4)->default(0);
            // Portion of tax_total already inside the line prices (inclusive).
            $table->decimal('tax_included_total', 20, 4)->default(0);
            $table->decimal('other_charges', 20, 4)->default(0);
            $table->decimal('rounding', 20, 4)->default(0);
            $table->decimal('grand_total', 20, 4)->default(0);
            $table->string('currency', 8)->default('IDR');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // A cashier works on one open cart per register, and that is
            // enforced by findOrCreateWorking() rather than the database: held
            // carts share the same key, and a null register_id is never distinct
            // in a unique index, so no index here can express "one *active* row".
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'user_id', 'status']);
            $table->index(['company_id', 'register_id', 'status']);
            $table->index('customer_id');
        });

        Schema::create('pos_cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_cart_id')->constrained('pos_carts')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->foreignId('tax_id')->nullable()->constrained()->nullOnDelete();

            // Identity snapshot, so a parked cart still reads correctly after a
            // product is renamed and without a join back to the catalogue.
            $table->string('product_name');
            $table->string('product_sku', 128);
            $table->string('barcode', 128)->nullable();
            $table->string('variant_name')->nullable();
            $table->string('unit_code', 32)->nullable();

            $table->decimal('quantity', 20, 6)->default(1);
            $table->decimal('unit_price', 20, 4)->default(0);
            // Where the unit price came from, so the till can show the cashier
            // which tier a customer is being billed at.
            // price_list | customer_group | branch | default_list | variant | product
            $table->string('price_source', 24)->default('product');
            $table->decimal('discount', 20, 4)->default(0);
            $table->string('discount_type', 12)->default('amount');
            $table->decimal('tax_rate', 10, 4)->default(0);
            // exclusive | inclusive, frozen with the line the same way a
            // purchase order line freezes its rate.
            $table->string('tax_mode', 16)->default('exclusive');

            $table->decimal('line_subtotal', 20, 4)->default(0);
            $table->decimal('discount_amount', 20, 4)->default(0);
            $table->decimal('tax_amount', 20, 4)->default(0);
            $table->decimal('line_total', 20, 4)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('pos_cart_id');
            $table->index(['company_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_cart_items');
        Schema::dropIfExists('pos_carts');
    }
};
