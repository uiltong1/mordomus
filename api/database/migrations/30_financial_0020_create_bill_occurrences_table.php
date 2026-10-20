<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_occurrences', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('tenant_id', 26);
            $table->char('bill_id', 26);
            $table->char('schedule_id', 26)->nullable();
            $table->date('due_date');
            $table->decimal('amount', 12, 2);
            $table->enum('status', ['open', 'paid', 'overdue', 'cancelled'])->default('open');
            $table->timestampTz('paid_at')->nullable();
            $table->char('paid_by', 26)->nullable();
            $table->timestamps();

            $table->unique(['bill_id', 'due_date']);
            $table->index(['tenant_id', 'status', 'due_date']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('bill_id')->references('id')->on('bills')->cascadeOnDelete();
            $table->foreign('paid_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_occurrences');
    }
};
