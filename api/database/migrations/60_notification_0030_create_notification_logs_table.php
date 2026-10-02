<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trilha do que foi notificado, para quem e com que resultado.
 *
 * A linha nasce `queued` — a intenção, com o instante em que *pode* sair, já
 * decidido pelas quiet hours — e vira `sent` ou `failed` quando o canal
 * responde. É essa linha que é a prova de que o aviso saiu e do que era
 * ele, e é dela que o sino (histórico) e a deduplicação leem.
 *
 * **`dedupe_key` único e global** é o que segura o at-least-once: a mesma
 * mensagem redelegada pela fila tenta a mesma chave e o banco recusa a segunda
 * linha, em vez de a casa receber o aviso duas vezes. A chave carrega o evento,
 * a chave do payload, o canal e o morador — o mesmo evento para duas pessoas
 * são duas notificações, e o mesmo evento para a mesma pessoa no mesmo canal é
 * uma.
 *
 * A linha é do **aviso**, e não da assinatura: um morador com dois
 * navegadores registrou uma notificação só, e quem faz o fan-out para cada
 * assinatura é o canal. A `dedupe_key` precisa disso para não contar como
 * notificação nova o segundo navegador.
 *
 * `body` é jsonb porque o aviso não é só texto: o payload de push (`title`,
 * `tag`, `url`, `data`) e o corpo do e-mail saem do mesmo lugar, e uma coluna
 * `text` obrigaria a duplicar a mensagem em dois formatos.
 *
 * `user_id` com `nullOnDelete`: a prova de que o aviso saiu continua valendo
 * depois que a pessoa sai da residência — é o mesmo motivo do lançamento de
 * pagamento no módulo Financial.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('tenant_id', 26);
            $table->char('user_id', 26)->nullable();
            $table->enum('channel', ['push', 'email', 'inapp']);
            $table->string('template', 120);
            $table->string('subject', 200);
            $table->jsonb('body');
            $table->string('dedupe_key', 200)->unique();
            $table->enum('status', ['queued', 'sent', 'failed'])->default('queued');
            $table->timestampTz('available_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            // Índice do sino (histórico do morador, mais recentes primeiro) e
            // da varredura de pendência: o que está `queued` e já pode sair.
            $table->index(['tenant_id', 'user_id', 'created_at']);
            $table->index(['status', 'available_at']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
