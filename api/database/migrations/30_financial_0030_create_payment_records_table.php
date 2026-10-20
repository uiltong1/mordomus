<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_records', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('tenant_id', 26);
            $table->char('bill_occurrence_id', 26);
            $table->char('user_id', 26)->nullable();
            $table->decimal('amount', 12, 2);
            $table->enum('method', ['pix', 'boleto', 'debit_card', 'credit_card', 'cash', 'transfer', 'other']);
            $table->timestampTz('paid_at');
            $table->string('receipt_url', 500)->nullable();

            $table->index(['tenant_id', 'paid_at']);
            $table->index(['bill_occurrence_id', 'paid_at']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('bill_occurrence_id')->references('id')->on('bill_occurrences')->cascadeOnDelete();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_records');
    }
};
