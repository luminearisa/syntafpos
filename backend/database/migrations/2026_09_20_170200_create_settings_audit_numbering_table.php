<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key', 128);
            $table->text('value')->nullable();
            $table->string('type', 16)->default('string');
            $table->string('group', 64)->default('general');
            $table->boolean('is_locked')->default(false);
            $table->timestamps();

            $table->unique(['company_id', 'key']);
            $table->index('group');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 64);
            $table->string('entity_type', 128)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['entity_type', 'entity_id']);
            $table->index('action');
        });

        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('document_type', 32);
            $table->string('prefix', 16)->default('DOC');
            $table->unsignedInteger('next_sequence')->default(1);
            $table->unsignedSmallInteger('padding')->default(6);
            $table->string('reset_period', 16)->default('yearly');
            $table->date('period_start')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'branch_id', 'document_type']);
            $table->index('document_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('settings');
    }
};
