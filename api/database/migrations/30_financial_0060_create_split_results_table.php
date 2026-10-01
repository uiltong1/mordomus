<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cota-parte calculada: quanto de um vencimento cabe a cada morador.
 *
 * É o resultado materializado, não a regra. A regra diz como dividir; esta
 * tabela diz o que foi dividido, com o valor que o morador realmente foi
 * cobrado no dia do cálculo. A separação é o que permite auditar uma conta já
 * quitada depois de a regra ter mudado — mexer na regra não reescreve o que
 * alguém já pagou.
 *
 * `unique(bill_occurrence_id, user_id)` é o que torna o recálculo idempotente:
 * rodar o cálculo de novo atualiza a linha existente em vez de abrir uma
 * segunda cota para o mesmo morador no mesmo vencimento.
 *
 * `settled_at` é o relógio da baixa (`settled` sozinho não diz quando o morador
 * quitou, e a conta do mês seguinte precisa dessa data para mostrar o que veio
 * antes). `nullOnDelete` no morador preserva o valor já dividido depois que a
 * pessoa sai da residência — o mesmo motivo do lançamento de pagamento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('split_results', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('tenant_id', 26);
            $table->char('bill_occurrence_id', 26);
            // Nulo é obrigatório para a FK `nullOnDelete` ter para onde levar
            // quando o morador sai da residência: sem o `nullable`, o banco
            // recusa o `DELETE` do usuário em vez de preservar a cota.
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
