<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Baixa de pagamento: o que foi pago, por quem, como e quando.
 *
 * A linha é append-only e não tem `created_at`/`updated_at` porque `paid_at`
 * é o único relógio dela — nada reescreve um pagamento depois de registrado.
 * Um lançamento de pagamento repetido não é corrigido: ele é a prova de que a
 * conta foi quitada, e o que a impede de virar duplicidade é a idempotência
 * do endpoint, não uma coluna que alguém ajustaria.
 *
 * O lançamento sabe o valor que foi pago e não o da ocorrência: em conta
 * variável (luz, água) o valor real só existe aqui.
 */
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
            // Quem pagou continua legível depois que a conta é removida: o
            // lançamento é histórico, e perder o autor dele só porque o morador
            // saiu da residência apagaria a prova de que a conta foi quitada.
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_records');
    }
};
