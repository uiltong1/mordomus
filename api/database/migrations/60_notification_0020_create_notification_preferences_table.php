<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('tenant_id', 26);
            $table->char('user_id', 26)->nullable();
            $table->time('quiet_start')->nullable();
            $table->time('quiet_end')->nullable();
            $table->enum('digest', ['instant', 'daily'])->nullable();
            $table->time('preferred_hour')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'user_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        DB::statement(
            'CREATE UNIQUE INDEX notification_preferences_house_unique'
            .' ON notification_preferences (tenant_id) WHERE user_id IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
