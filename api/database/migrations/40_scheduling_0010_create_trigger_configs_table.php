<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Regras de recorrência do módulo Scheduling: a única fonte de datas futuras.
 *
 * O alvo do trigger é polimórfico e o PostgreSQL não aceita FK condicional,
 * então há uma coluna por alvo (`asset_id`/`bill_id`) e um CHECK de exclusão
 * mútua garante que exatamente uma delas esteja preenchida, de acordo com
 * `subject_type`. A exclusão precisa ser uma disjunção só: dois CHECK
 * separados (um por alvo) se excluiriam entre si e nenhuma linha passaria
 * nos dois.
 *
 * O título é único por alvo. Como uma das duas colunas é sempre NULL e NULL
 * não colide em índice único, o índice vai sobre a expressão
 * `coalesce(asset_id, bill_id)` — é ele que resolve o "um título por alvo"
 * em uma única restrição.
 *
 * `bill_id` tem FK para `bills` (ADR-011) porque a tabela do módulo dono da
 * conta é criada antes desta (`30_financial`). Declarar a chave aqui, e não em
 * `50_financial_links`, é exigência do SQLite: acrescentar chave estrangeira
 * com `ALTER TABLE` reconstrói a tabela e o banco perde as garantias que o
 * `CREATE TABLE` tinha criado — o CHECK de exclusão mútua e o índice único
 * sobre a expressão `coalesce`, que é o que faz "um título por alvo" valer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trigger_configs', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('tenant_id', 26);
            $table->enum('subject_type', ['asset', 'bill']);
            $table->char('asset_id', 26)->nullable();
            $table->char('bill_id', 26)->nullable();
            $table->string('title', 160);
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->enum('type', ['INTERVAL', 'CALENDAR_MONTHLY', 'POST_COMPLETION', 'ESCALATED']);
            $table->unsignedSmallInteger('interval_value')->nullable();
            $table->enum('interval_unit', ['days', 'weeks', 'months'])->nullable();
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->unsignedSmallInteger('advance_notice_days')->default(0);
            $table->enum('recalculate_base', ['DUE_DATE', 'COMPLETION'])->nullable();
            $table->jsonb('custom_offsets')->nullable();
            $table->time('preferred_hour')->nullable();
            $table->date('last_base_date')->nullable();
            $table->timestampTz('next_due_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'subject_type']);
            $table->index(['tenant_id', 'is_active', 'next_due_at']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('asset_id')->references('id')->on('assets')->cascadeOnDelete();
            $table->foreign('bill_id')->references('id')->on('bills')->cascadeOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE trigger_configs
            ADD CONSTRAINT ck_trigger_configs_subject_exclusive
            CHECK (
                (subject_type = 'asset' AND asset_id IS NOT NULL AND bill_id IS NULL)
                OR (subject_type = 'bill' AND bill_id IS NOT NULL AND asset_id IS NULL)
            )
            SQL);

        DB::statement(
            'CREATE UNIQUE INDEX trigger_configs_subject_title_unique'
            .' ON trigger_configs (tenant_id, subject_type, coalesce(asset_id, bill_id), title)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('trigger_configs');
    }
};
