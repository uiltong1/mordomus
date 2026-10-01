<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moradores que participam de uma regra, com o peso de cada um no cálculo.
 *
 * Não tem `tenant_id`: a entrada não existe sozinha, ela pertence a uma regra
 * (`split_rule_id`) que já carrega a residência. A tabela é sempre lida pelo
 * caminho da regra, e é por isso que a fronteira de tenant não precisa ser
 * repetida aqui — duplicá-la criaria duas colunas para o mesmo fato e a chance
 * de elas divergirem.
 *
 * Só o campo do regime em uso é gravado: `weight` em `WEIGHTED`, `percent` em
 * `PERCENT`, `fixed_amount` em `CUSTOM`. Os outros ficam nulos, e é o
 * `SplitCalculator` que sabe qual deles o regime pede. Guardar os três
 * preenchidos deixaria a linha ambígua: qual deles vale quando os três estão
 * ali?
 *
 * `unique(split_rule_id, user_id)` é o que impede o mesmo morador de entrar
 * duas vezes na regra — o que faria a soma das cotas fechar com o total e a
 * conta divisionária estar errada.
 */
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
            // Cascata: sair da residência tira o morador da regra, e a regra
            // sem ele deixa de ser computável. O resultado já calculado é que
            // preserva o histórico — e ele tem FK própria para o vencimento.
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('split_entries');
    }
};
