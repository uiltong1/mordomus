<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trilha imutável do que aconteceu com cada ocorrência.
 *
 * A linha é append-only: não há `created_at`/`updated_at` porque
 * `occurred_at` é o único relógio, e nada reescreve o registro depois de
 * inserido.
 *
 * `actor` é nulo quando o evento veio da fila e não de um morador; a FK é
 * `nullOnDelete` para que a trilha sobreviva à exclusão de conta e o
 * histórico continue legível.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_events', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('tenant_id', 26);
            $table->char('job_schedule_id', 26);
            $table->enum('event', ['CREATED', 'NOTIFIED', 'COMPLETED', 'SKIPPED']);
            $table->char('actor', 26)->nullable();
            $table->jsonb('payload')->nullable();
            $table->timestampTz('occurred_at');

            $table->index(['job_schedule_id', 'occurred_at']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('job_schedule_id')->references('id')->on('job_schedules')->cascadeOnDelete();
            $table->foreign('actor')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_events');
    }
};
