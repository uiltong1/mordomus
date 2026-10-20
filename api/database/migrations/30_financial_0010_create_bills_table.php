<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bills', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('tenant_id', 26);
            $table->string('name', 120);
            $table->enum('kind', ['fixed', 'variable']);
            $table->string('category', 40)->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->char('currency', 3)->default('BRL');
            $table->boolean('is_active')->default(true);
            $table->char('created_by', 26)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'is_active', 'kind']);
            $table->index(['tenant_id', 'category']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bills');
    }
};
