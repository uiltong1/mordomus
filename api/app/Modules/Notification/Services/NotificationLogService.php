<?php

declare(strict_types=1);

namespace Mordomus\Notification\Services;

use Illuminate\Http\Request;
use Mordomus\Http\Exceptions\TenantMismatch;
use Mordomus\Http\Pagination\OffsetPagination;
use Mordomus\Http\Tenancy\ActiveTenant;
use Mordomus\Notification\Contracts\Repositories\NotificationLogRepositoryInterface;
use Mordomus\Notification\Contracts\Services\NotificationLogServiceInterface;
use Mordomus\Notification\Http\Resources\NotificationLogResource;
use Mordomus\Scheduling\Contracts\Services\TenantCalendarServiceInterface;

/**
 * Histórico do que o morador recebeu.
 *
 * É a resposta à pergunta "por que não recebi?" — e por isso mostra também o
 * que **falhou** e o motivo. Um histórico que só lista o que deu certo deixa o
 * morador sem resposta exatamente quando ele mais precisa dela.
 *
 * O recorte é o do chamador: a lista é dele, e o de mais ninguém. Sem
 * capability porque não há nada de outro morador no escopo.
 */
final class NotificationLogService implements NotificationLogServiceInterface
{
    public function __construct(
        private readonly NotificationLogRepositoryInterface $logs,
        private readonly NotificationLogResource $resource,
        private readonly TenantCalendarServiceInterface $calendar,
    ) {}

    public function index(Request $request): array
    {
        $tenantId = $this->tenantId($request);
        $userId = (string) $request->user()->id;
        $pagination = OffsetPagination::from($request);

        $channel = $request->input('channel');

        $logs = $this->logs->paginateForUser(
            $tenantId,
            $userId,
            is_string($channel) && $channel !== '' ? $channel : null,
            $pagination,
        );

        return [
            'data' => $this->resource->collection($logs->getCollection(), $this->calendar->timezone($tenantId)),
            'meta' => $pagination->meta($logs->total(), $logs->lastPage()),
        ];
    }

    private function tenantId(Request $request): string
    {
        // O middleware `tenant` já devolveu 403 sem `tid`; o guard serve para
        // o service nunca receber um id vazio e vazar escopo.
        return ActiveTenant::id($request) ?? throw TenantMismatch::make();
    }
}
