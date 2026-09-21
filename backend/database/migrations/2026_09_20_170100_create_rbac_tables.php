<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 128)->unique();
            $table->string('display_name');
            $table->string('group', 64)->default('system');
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 128);
            $table->string('display_name');
            $table->string('guard_name', 32)->default('web');
            $table->string('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'name', 'guard_name']);
            $table->index('company_id');
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['permission_id', 'role_id']);
        });

        Schema::create('role_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'role_id']);
        });

        // Business context membership pivots.
        foreach (['company', 'branch', 'warehouse', 'register'] as $entity) {
            Schema::create("{$entity}_user", function (Blueprint $table) use ($entity) {
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId("{$entity}_id")->constrained()->cascadeOnDelete();
                $table->primary(['user_id', "{$entity}_id"]);
            });
        }
    }

    public function down(): void
    {
        foreach (['company', 'branch', 'warehouse', 'register'] as $entity) {
            Schema::dropIfExists("{$entity}_user");
        }
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
