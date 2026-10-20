<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('split_entries', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('split_rule_id', 26);
            $table->char('user_id', 26);
            $table->decimal('weight', 6, 2)->nullable();
            $table->decimal('percent', 5, 2)->nullable();
            $table->decimal('fixed_amount', 12, 2)->nullable();
            $table->timestamps();

            $table->unique(['split_rule_id', 'user_id']);
            $table->index('user_id');
            $table->foreign('split_rule_id')->references('id')->on('split_rules')->cascadeOnDelete();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('split_entries');
    }
};
