<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventário de ativos do módulo Maintenance.
 *
 * `acquired_at`/`warranty_until` são `timestamptz` para que a API possa
 * devolvê-los no fuso do tenant. Soft delete via `archived_at` e FK real
 * para `rooms`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('tenant_id', 26);
            $table->char('room_id', 26);
            $table->string('name', 120);
            $table->string('category', 40)->nullable();
            $table->string('brand', 60)->nullable();
            $table->string('model', 60)->nullable();
            $table->timestampTz('acquired_at')->nullable();
            $table->timestampTz('warranty_until')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'room_id']);
            $table->index(['tenant_id', 'category']);
            $table->index('archived_at');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('room_id')->references('id')->on('rooms')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
