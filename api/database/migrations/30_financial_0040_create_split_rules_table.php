<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('split_rules', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('tenant_id', 26);
            $table->char('bill_id', 26)->nullable();
            $table->enum('mode', ['EQUAL', 'WEIGHTED', 'PERCENT', 'CUSTOM']);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
            $table->index(['tenant_id', 'bill_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->foreign('bill_id')->references('id')->on('bills')->cascadeOnDelete();
        });

        DB::statement(
            'CREATE UNIQUE INDEX split_rules_tenant_bill_unique'
            .' ON split_rules (tenant_id, coalesce(bill_id, \'\'))'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('split_rules');
    }
};
