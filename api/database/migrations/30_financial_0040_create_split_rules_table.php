<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Regras de divisão de cota-parte: como a casa reparte uma conta entre os
 * moradores.
 *
 * `bill_id` nulo é a regra padrão da casa — a que vale para toda conta sem
 * regra própria. O índice único precisa cobrir os dois casos num só
 * construtor, e `NULL` não colide em índice único: dois "padrão da casa"
 * passariam sem reclamar, e a casa ficaria com duas regras valendo ao mesmo
 * tempo para a mesma conta. O `coalesce` transforma o nulo em string vazia e
 * resolve a ambiguidade numa restrição só.
 *
 * `mode` é o regime do cálculo, não uma preferência de tela: quem escolhe
 * `WEIGHTED` está gravando um peso por morador, e o motor lê exatamente esse
 * campo. Os campos que o regime não usa saem nulos.
 *
 * As entradas moram na tabela ao lado (`split_entries`) porque a cota é do
 * morador dentro da regra, e não uma propriedade dela.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('split_rules', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('tenant_id', 26);
            $table->char('bill_id', 26)->nullable();
            $table->enum('mode', ['EQUAL', 'WEIGHTED', 'PERCENT', 'CUSTOM']);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
            $table->index(['tenant_id', 'bill_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // Cascata: a regra de divisão é configuração da conta e não sobrevive
            // a ela — o que sobrevive é o resultado já calculado.
            $table->foreign('bill_id')->references('id')->on('bills')->cascadeOnDelete();
        });

        DB::statement(
            'CREATE UNIQUE INDEX split_rules_tenant_bill_unique'
            .' ON split_rules (tenant_id, coalesce(bill_id, \'\'))'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('split_rules');
    }
};
