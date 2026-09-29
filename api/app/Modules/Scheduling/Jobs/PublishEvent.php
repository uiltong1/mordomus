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
use Throwable;

/**
 * Mensagem de evento na fila do Notification.
 *
 * A entrega é a própria fila (SDD §5): não há HTTP entre módulos, e o
 * envelope viaja como job para que o `api-worker` a retire com a política de
 * retry e a DLQ de um só lugar. O tratamento da mensagem é do módulo
 * Notification (T6.1) — aqui não sobra o que fazer além de mantê-la viva e
 * observável.
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

    public function handle(): void {}

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
