<?php

declare(strict_types=1);

namespace Mordomus\Notification\Services;

use Illuminate\Http\Request;
use Mordomus\Http\Exceptions\TenantMismatch;
use Mordomus\Http\Tenancy\ActiveTenant;
use Mordomus\Notification\Contracts\Repositories\NotificationPreferenceRepositoryInterface;
use Mordomus\Notification\Contracts\Services\NotificationPreferenceServiceInterface;
use Mordomus\Notification\Contracts\Services\QuietWindowServiceInterface;
use Mordomus\Notification\Http\Resources\NotificationPreferenceResource;

/**
 * Quiet hours, digest e horário preferido do morador.
 *
 * A resposta traz as três camadas — a do morador, a da casa e a que vale de
 * fato — porque a tela precisa mostrar o que está valendo e não só o que foi
 * digitado. O que ela mostra é a **precedência resolvida**, e o PUT grava
 * apenas a linha do morador: quem escreve aqui nunca mexe no padrão da casa,
 * que é decisão de quem administra a residência.
 *
 * A escrita substitui o conjunto — o PUT manda tudo — e a linha do morador é
 * reescrita em vez de mesclada: um campo que sai do payload é um campo que o
 * morador quer voltar ao padrão, e a mesclagem não saberia da diferença entre
 * "esqueci" e "quero o padrão".
 */
final class NotificationPreferenceService implements NotificationPreferenceServiceInterface
{
    public function __construct(
        private readonly NotificationPreferenceRepositoryInterface $preferences,
        private readonly QuietWindowServiceInterface $quiet,
        private readonly NotificationPreferenceResource $resource,
    ) {}

    public function show(Request $request): array
    {
        $tenantId = $this->tenantId($request);
        $userId = (string) $request->user()->id;

        return ['data' => $this->resource->make(
            $this->preferences->findForUser($tenantId, $userId),
            $this->preferences->findForHouse($tenantId),
            $this->quiet->effective($tenantId, $userId),
        )];
    }

    public function update(Request $request): array
    {
        $tenantId = $this->tenantId($request);
        $userId = (string) $request->user()->id;

        $preference = $this->preferences->upsertForUser($tenantId, $userId, [
            'quiet_start' => $request->input('quiet_start'),
            'quiet_end' => $request->input('quiet_end'),
            // `input` e não `string`: o PUT substitui o conjunto, e um campo
            // ausente é o morador querendo voltar ao padrão. `string()`
            // transformaria a ausência em string vazia, que o `CHECK` do banco
            // recusa — e a gravação de um "voltar ao padrão" viraria 500.
            'digest' => $request->input('digest'),
            'preferred_hour' => $request->input('preferred_hour'),
        ]);

        return ['data' => $this->resource->make(
            $preference,
            $this->preferences->findForHouse($tenantId),
            $this->quiet->effective($tenantId, $userId),
        )];
    }

    private function tenantId(Request $request): string
    {
        // O middleware `tenant` já devolveu 403 sem `tid`; o guard serve para
        // o service nunca receber um id vazio e vazar escopo.
        return ActiveTenant::id($request) ?? throw TenantMismatch::make();
    }
}
