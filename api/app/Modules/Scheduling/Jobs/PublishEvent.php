<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Mordomus\Scheduling\Events\QueuedEnvelope;
use Throwable;

/**
 * Mensagem de evento na fila do Notification.
 *
 * A entrega é a própria fila (SDD §5): não há HTTP entre módulos, e o
 * envelope viaja como job para que o `api-worker` a retire com a política de
 * retry e a DLQ de um só lugar. O tratamento da mensagem é do módulo
 * Notification — este job só a entrega e avisa que ela chegou.
 */
class PublishEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    /**
     * @param  array<string, mixed>  $envelope
     */
    public function __construct(public readonly array $envelope) {}

    /**
     * Entrega o envelope a quem o trata.
     *
     * O evento é síncrono e roda dentro desta tentativa: se o consumidor
     * falhar, a exceção sobe aqui, o job repete, e a mensagem vai para a DLQ
     * com o motivo colado. Publicar outro evento e sair de mãos abanadas
     * entregaria a falha da fila sem que ninguém soubesse.
     */
    public function handle(): void
    {
        event(new QueuedEnvelope($this->envelope));
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('scheduling.event_failed', [
            'event' => $this->envelope['event'] ?? null,
            'event_id' => $this->envelope['event_id'] ?? null,
            'tenant_id' => $this->envelope['tenant_id'] ?? null,
            'exception' => $exception,
            'dead_lettered_at' => CarbonImmutable::now('UTC')->toIso8601String(),
        ]);
    }
}
