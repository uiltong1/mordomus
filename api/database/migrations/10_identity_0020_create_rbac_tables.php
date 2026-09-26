<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RBAC: roles, capabilities e overrides por membership.
 * Papel `member`/`owner` é role de sistema (tenant_id nulo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('tenant_id', 26)->nullable();
            $table->string('key', 64);
            $table->string('name', 120);
            $table->boolean('is_system')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'key']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->string('key', 64)->unique();
            $table->string('description', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->char('role_id', 26);
            $table->char('permission_id', 26);
            $table->boolean('granted')->default(true);
            $table->primary(['role_id', 'permission_id']);
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
        });

        Schema::create('memberships', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('user_id', 26);
            $table->char('tenant_id', 26);
            $table->char('role_id', 26);
            $table->enum('status', ['active', 'invited', 'archived'])->default('active');
            $table->timestamps();
            $table->unique(['user_id', 'tenant_id']);
            $table->index('tenant_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->restrictOnDelete();
        });

        Schema::create('membership_grants', function (Blueprint $table) {
            $table->char('membership_id', 26);
            $table->char('permission_id', 26);
            $table->boolean('granted')->default(true);
            $table->primary(['membership_id', 'permission_id']);
            $table->foreign('membership_id')->references('id')->on('memberships')->cascadeOnDelete();
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_grants');
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
