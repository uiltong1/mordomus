<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ocorrências materializadas do motor de agendamento.
 *
 * `scheduled_for` é o dia do calendário a que a ocorrência pertence e
 * `due_at` é o instante em que ela deve disparar (o mesmo dia, na
 * `preferred_hour` resolvida do trigger ou da residência) — as duas colunas
 * existem separadas porque o varrimento do scheduler pergunta por instante
 * e a.groupby por dia.
 *
 * `unique(trigger_config_id, scheduled_for)` é a garantia de idempotência do
 * recálculo: concluir a mesma ocorrência duas vezes tenta escrever a mesma
 * data para o mesmo trigger, e o banco recusa a segunda linha em vez de
 * duplicar o ciclo.
 *
 * Não há soft delete: dispensar uma ocorrência é `status = skipped`, que
 * preserva a linha como histórico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_schedules', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('tenant_id', 26);
            $table->char('trigger_config_id', 26);
            $table->date('scheduled_for');
            $table->timestampTz('due_at');
            $table->enum('status', ['pending', 'notified', 'completed', 'skipped', 'overdue'])->default('pending');
            $table->timestampTz('notified_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->char('completed_by', 26)->nullable();
            $table->timestamps();

            $table->unique(['trigger_config_id', 'scheduled_for']);
            $table->index(['tenant_id', 'status', 'due_at']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('trigger_config_id')->references('id')->on('trigger_configs')->cascadeOnDelete();
            $table->foreign('completed_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_schedules');
    }
};
