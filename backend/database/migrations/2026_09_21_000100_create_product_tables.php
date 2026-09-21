<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('default_unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->foreignId('tax_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku', 128);
            // Primary barcode kept on the row for point-of-sale lookups;
            // additional codes live in product_barcodes.
            $table->string('barcode', 128)->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            // simple | variable | service | bundle | raw_material | finished_good | consumable
            $table->string('product_type', 32)->default('simple');
            $table->boolean('track_inventory')->default(true);
            $table->boolean('allow_negative_stock')->default(false);
            $table->boolean('is_sellable')->default(true);
            $table->boolean('is_purchasable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->decimal('cost_price', 20, 4)->default(0);
            $table->decimal('selling_price', 20, 4)->default(0);
            $table->decimal('minimum_selling_price', 20, 4)->nullable();
            $table->decimal('weight', 12, 4)->nullable();
            $table->decimal('length', 12, 4)->nullable();
            $table->decimal('width', 12, 4)->nullable();
            $table->decimal('height', 12, 4)->nullable();
            // Reorder policy.
            $table->decimal('minimum_stock', 20, 6)->default(0);
            $table->decimal('maximum_stock', 20, 6)->nullable();
            $table->decimal('reorder_point', 20, 6)->default(0);
            $table->decimal('reorder_quantity', 20, 6)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'sku']);
            $table->index(['company_id', 'barcode']);
            $table->index(['company_id', 'name']);
            $table->index(['company_id', 'product_type']);
            $table->index(['company_id', 'is_active']);
            $table->index('category_id');
            $table->index('brand_id');
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku', 128);
            $table->string('barcode', 128)->nullable();
            $table->string('name');
            $table->decimal('cost_price', 20, 4)->default(0);
            $table->decimal('selling_price', 20, 4)->default(0);
            $table->decimal('weight', 12, 4)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'sku']);
            $table->index(['company_id', 'barcode']);
            $table->index('product_id');
        });

        // Many-to-many between variants and the reusable attribute values.
        Schema::create('product_variant_attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attribute_value_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['product_variant_id', 'attribute_value_id']);
        });

        // A product may carry several codes; the same physical code may not be
        // reused within a company.
        Schema::create('product_barcodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code', 128);
            // ean | upc | code128 | qr | internal
            $table->string('type', 16)->default('internal');
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index('product_id');
        });

        // Price lists: retail / wholesale / member / reseller tiers scoped by
        // customer group, branch and an optional validity window.
        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_group_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('currency', 8)->default('IDR');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('status', 24)->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('price_type', 24)->default('retail');
            $table->decimal('price', 20, 4)->default(0);
            $table->decimal('minimum_price', 20, 4)->nullable();
            $table->timestamps();

            $table->unique(['price_list_id', 'product_id', 'product_variant_id', 'price_type']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_prices');
        Schema::dropIfExists('price_lists');
        Schema::dropIfExists('product_barcodes');
        Schema::dropIfExists('product_variant_attribute_values');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
    }
};
