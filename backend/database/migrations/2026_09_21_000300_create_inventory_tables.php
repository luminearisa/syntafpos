<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Current balance cache, keyed to the finest grain stock is tracked at.
        // Derived from stock_movements; never written to directly by a caller.
        Schema::create('stock_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('warehouse_locations')->nullOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();
            $table->decimal('on_hand', 20, 6)->default(0);
            $table->decimal('reserved', 20, 6)->default(0);
            $table->decimal('incoming', 20, 6)->default(0);
            $table->decimal('outgoing', 20, 6)->default(0);
            // Weighted average cost maintained on every receipt.
            $table->decimal('average_cost', 20, 4)->default(0);
            $table->decimal('last_cost', 20, 4)->default(0);
            $table->timestamp('last_movement_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'warehouse_id', 'location_id', 'product_id', 'product_variant_id', 'unit_id']);
            $table->index(['company_id', 'product_id']);
            $table->index(['warehouse_id', 'product_id']);
            $table->index('product_variant_id');
        });

        // Immutable ledger. Every quantity change is one signed row, so the
        // running balance is reproducible by summing a product's history.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('warehouse_locations')->nullOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // opening | purchase | purchase_return | sale | sale_return |
            // transfer_out | transfer_in | adjustment_in | adjustment_out |
            // production_in | production_out | consumption | stock_opname
            $table->string('movement_type', 32);
            // Polymorphic pointer to the document that caused this movement.
            $table->string('reference_type', 64)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            // Signed: positive into stock, positive out of stock is negative.
            $table->decimal('quantity', 20, 6);
            $table->decimal('unit_cost', 20, 4)->default(0);
            $table->decimal('total_cost', 20, 4)->default(0);
            $table->decimal('balance_after', 20, 6)->default(0);
            $table->timestamp('occurred_at')->useCurrent();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'warehouse_id', 'product_id']);
            $table->index(['product_id', 'product_variant_id', 'occurred_at']);
            $table->index(['warehouse_id', 'occurred_at']);
            $table->index('movement_type');
            $table->index(['reference_type', 'reference_id']);
            $table->index('location_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_balances');
    }
};
