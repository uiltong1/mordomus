<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('split_results', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('tenant_id', 26);
            $table->char('bill_occurrence_id', 26);

            $table->char('user_id', 26)->nullable();
            $table->decimal('share_amount', 12, 2);
            $table->boolean('settled')->default(false);
            $table->timestampTz('settled_at')->nullable();
            $table->timestamps();

            $table->unique(['bill_occurrence_id', 'user_id']);
            $table->index(['tenant_id', 'user_id', 'settled']);
            $table->index(['tenant_id', 'bill_occurrence_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('bill_occurrence_id')->references('id')->on('bill_occurrences')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('split_results');
    }
};
