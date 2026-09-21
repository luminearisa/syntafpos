<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 24)->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'name']);
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_group_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('price_list_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_code', 64);
            $table->string('name');
            // individual | company
            $table->string('type', 16)->default('individual');
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('province')->nullable();
            $table->string('country', 64)->default('Indonesia');
            $table->string('postal_code', 16)->nullable();
            $table->string('tax_number', 64)->nullable();
            $table->decimal('credit_limit', 20, 4)->default(0);
            $table->unsignedSmallInteger('payment_terms')->default(0);
            $table->date('birthday')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'customer_code']);
            $table->index(['company_id', 'name']);
            $table->index(['company_id', 'phone']);
            $table->index(['company_id', 'is_active']);
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('supplier_code', 64);
            $table->string('name');
            $table->string('company_name')->nullable();
            $table->string('contact_person', 128)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('province')->nullable();
            $table->string('country', 64)->default('Indonesia');
            $table->string('postal_code', 16)->nullable();
            $table->string('tax_number', 64)->nullable();
            $table->unsignedSmallInteger('payment_terms')->default(0);
            $table->decimal('credit_limit', 20, 4)->default(0);
            $table->string('bank_name', 128)->nullable();
            $table->string('bank_account', 64)->nullable();
            $table->string('bank_account_name', 128)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 24)->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'supplier_code']);
            $table->index(['company_id', 'name']);
            $table->index(['company_id', 'status']);
        });

        // Storage locations nest under a warehouse. Depth is generic: a zone
        // may hold racks, a rack may hold bins.
        Schema::create('warehouse_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('warehouse_locations')->nullOnDelete();
            $table->string('code', 64);
            $table->string('name');
            // zone | rack | bin | area
            $table->string('type', 24)->default('zone');
            $table->unsignedSmallInteger('level')->default(0);
            $table->string('status', 24)->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['warehouse_id', 'code']);
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_locations');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('customer_groups');
    }
};
