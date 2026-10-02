<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quiet hours, digest e horário preferido — de quem e da casa.
 *
 * `user_id` nulo é a preferência da casa: o padrão que vale para quem não
 * configurou o seu. A precedência é morador → casa → padrão do ambiente, e ela
 * só tem uma fonte se cada lado puder existir uma vez — daí os dois índices:
 * `unique(tenant_id, user_id)` para a linha de cada morador, e um índice
 * parcial para a da casa, porque `NULL` não colide em índice único e duas
 * linhas de padrão fariam a precedência depender de qual o banco devolveu
 * primeiro.
 *
 * `quiet_start`/`quiet_end` guardam a janela de silêncio, e ela **vira a
 * meia-noite**: 22:00–07:00 é uma janela só, não duas. A checagem é do
 * `QuietWindowService`, que sabe que `quiet_start` maior que `quiet_end`
 * significa janela atravessada; gravar isso como 22:00–23:59 e 00:00–07:00
 * seria mais simples e mentiria para o morador.
 *
 * Os campos podem ser nulos: quem não tem preferência não tem linha, e a
 * ausência é o que o ambiente resolve. Uma linha com tudo nulo seria uma
 * quarta fonte, e ela não diz de nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('tenant_id', 26);
            $table->char('user_id', 26)->nullable();
            $table->time('quiet_start')->nullable();
            $table->time('quiet_end')->nullable();
            $table->enum('digest', ['instant', 'daily'])->nullable();
            $table->time('preferred_hour')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'user_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        DB::statement(
            'CREATE UNIQUE INDEX notification_preferences_house_unique'
            .' ON notification_preferences (tenant_id) WHERE user_id IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
