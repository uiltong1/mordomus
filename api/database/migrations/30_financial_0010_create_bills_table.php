<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contas da residência: o que a casa paga, e a que regime.
 *
 * `kind` separa as duas formas de pagar. `fixed` tem valor conhecido e o
 * vencimento vem da cadência que o Scheduling materializa; `variable` tem
 * valor que só se sabe quando a conta chega (luz, água), e o morador registra
 * a ocorrência na mão. O valor é nulo justamente para o caso variável — a
 * coluna `amount` na conta é o que se espera, e o que foi pago está no
 * lançamento.
 *
 * Não existe `trigger_config_id` aqui: o vínculo com a cadência é lido a
 * partir de `trigger_configs.bill_id`, que é FK real (ADR-011). Duplicar o
 * caminho criaria dois lugares para o mesmo vínculo e um ciclo entre tabelas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bills', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('tenant_id', 26);
            $table->string('name', 120);
            $table->enum('kind', ['fixed', 'variable']);
            $table->string('category', 40)->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->char('currency', 3)->default('BRL');
            $table->boolean('is_active')->default(true);
            $table->char('created_by', 26)->nullable();
            $table->timestamps();

            // Índice da lista (filtro por regime e estado) e do resumo mensal
            // (agrupamento por categoria).
            $table->index(['tenant_id', 'is_active', 'kind']);
            $table->index(['tenant_id', 'category']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // `nullOnDelete`: sair da residência não pode apagar o histórico
            // de contas e vencimentos já quitados.
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bills');
    }
};
