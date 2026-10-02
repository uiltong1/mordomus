<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assinaturas de Web Push dos moradores (ADR-001).
 *
 * A linha é uma assinatura do navegador, não um aparelho: o mesmo celular
 * pode assinar em dois navegadores, e trocar de navegador não deve apagar o
 * histórico. O `endpoint` é a chave natural — é o endereço que o serviço de
 * push entrega, e é por ele que a assinatura morta é reconhecida e removida.
 *
 * `unique(user_id, endpoint)` é o que impede a mesma assinatura virar duas
 * linhas quando o sino é clicado duas vezes: o reenvio atualiza o
 * `last_seen_at` em vez de duplicar, e é o que deixa a limpeza de endpoint
 * expirado ter uma linha só para apagar.
 *
 * `p256dh` e `auth` são a chave pública do assinante e o segredo de
 * autenticação, em base64url. Juntas com o endpoint composant a assinatura que
 * o canal criptografa (RFC 8291); `fcm_token` fica para o adapter nativo, que
 * não usa Web Push e por isso nasce nulo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('tenant_id', 26);
            $table->char('user_id', 26);
            $table->enum('platform', ['web', 'android', 'ios'])->default('web');
            $table->text('endpoint');
            $table->text('p256dh');
            $table->text('auth');
            $table->text('fcm_token')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'endpoint']);
            // Índice da varredura de entrega: quem tem assinatura ativa para
            // este morador, sem varrer as assinaturas mortas de todo mundo.
            $table->index(['tenant_id', 'user_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // Cascata: a assinatura pertence a quem assinou, e uma assinatura
            // de alguém que saiu da residência não é de ninguém.
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
