<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ambientes do imóvel (cômodos) do módulo Maintenance.
 *
 * Soft delete via `archived_at`, ordenação persistida em `sort_order` e
 * FK real de `tenant_id` para `tenants`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('tenant_id', 26);
            $table->string('name', 80);
            $table->string('icon', 40)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampTz('archived_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'sort_order']);
            $table->index('archived_at');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
