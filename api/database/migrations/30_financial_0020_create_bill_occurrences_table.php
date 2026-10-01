<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vencimentos de conta: uma linha por dia a pagar.
 *
 * `schedule_id` é o elo com o motor de agendamento e nasce sem FK, porque
 * `job_schedules` é criada depois (`40_scheduling`); a referência é adicionada
 * em `50_financial_links`. Nulo significa lançamento manual — a conta
 * variável não tem cadência, e quem registra é o morador, com a data que
 * ele viu na conta.
 *
 * `due_date` é dia de calendário e não vem do relógio do servidor: o módulo
 * Financial não calcula data nenhuma (regra R7). Ela chega pronta pelo evento
 * `schedule.occurrence.created`, ou vem do morador no lançamento manual.
 *
 * `unique(bill_id, due_date)` é a garantia de idempotência do consumidor: o
 * mesmo evento reentregue pela fila tenta a mesma data para a mesma conta, e
 * o banco recusa a segunda linha em vez de duplicar o vencimento.
 */
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
