<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fecha o ciclo Financial ↔ Scheduling com a chave estrangeira de verdade.
 *
 * `bill_occurrences.schedule_id` nasce em `30_financial` e a FK entra aqui,
 * depois de `40_scheduling`: a referência aponta para uma tabela que só existe
 * depois, e o passo existe por isso. A outra ponta do ciclo
 * (`trigger_configs.bill_id → bills(id)`) é declarada na própria migration da
 * tabela, porque `bills` vem antes dela.
 *
 * É a referência que a ADR-005 trocou por `subject_id` frouxo: com a FK, um
 * vencimento só pode apontar para uma ocorrência que existe de verdade.
 *
 * No SQLite a chave é acrescentada com `ALTER TABLE`, que reconstrói a tabela
 * e leva embora as restrições que o `CREATE TABLE` não descreve por conta
 * própria — entre elas o CHECK de `status`. A garantia real de estado vem da
 * coluna `enum` do PostgreSQL, e o módulo não tem outro caminho para gravar
 * um estado fora da lista (a API valida e o service só escreve os quatro).
 */
return new class extends Migration
{
    public function up(): void
    {
        // `nullOnDelete` e não cascata: apagar a regra de recorrência não pode
        // apagar o histórico financeiro da conta. O vencimento continua na
        // lista com a data e o valor, apenas sem a ligação com a agenda.
        Schema::table('bill_occurrences', function (Blueprint $table) {
            $table->foreign('schedule_id')->references('id')->on('job_schedules')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bill_occurrences', function (Blueprint $table) {
            $table->dropForeign(['schedule_id']);
        });
    }
};
