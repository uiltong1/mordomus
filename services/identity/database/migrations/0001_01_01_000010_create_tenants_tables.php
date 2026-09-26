<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T1.2.1 — residências (tenants) + preferências do tenant (T1.2.7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->string('timezone', 64)->default('America/Sao_Paulo');
            $table->time('preferred_hour')->default('09:00');
            $table->timestampTz('archived_at')->nullable();
            $table->timestamps();
            $table->index('archived_at');
        });

        Schema::create('tenant_preferences', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('tenant_id', 26)->unique();
            $table->json('quiet_hours')->nullable();
            $table->json('channels')->nullable();
            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_preferences');
        Schema::dropIfExists('tenants');
    }
};
