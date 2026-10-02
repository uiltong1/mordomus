<?php

declare(strict_types=1);

namespace Mordomus\Notification\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Mordomus\Common\Support\TenantContext;
use Mordomus\Notification\Contracts\Services\NotificationDeliveryServiceInterface;
use Mordomus\Notification\Models\NotificationLog;
use Throwable;

/**
 * Entrega de uma notificação, com retry e DLQ.
 *
 * O retry mora aqui e não no consumidor porque a falha é do **provedor**: o
 * serviço de push fora do ar, o SMTP recusando. O consumidor é uma escrita, e
 * uma escrita que falha é problema de banco, não de entrega.
 *
 * O modelo viaja serializado e é reidratado do banco a cada tentativa, e é por
 * isso que uma tentativa posterior enxerga o `sent_at` de uma anterior: sem
 * isso, o reenvio depois de um sucesso parcial mandaria o mesmo aviso de novo.
 *
 * A linha da notificação é o que sobrevive à DLQ. A `failed_jobs` do Laravel
 * guarda a exceção para inspeção, e `NotificationDeliveryService::fail()`
 * deixa a linha em `failed` — que é o que a tela de histórico mostra quando
 * o morador pergunta por que não recebeu.
 */
class DeliverNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    /** @var list<int> */
    public array $backoff;

    public function __construct(public readonly NotificationLog $log)
    {
        $this->tries = (int) config('notification.delivery.tries', 3);
        $this->backoff = (array) config('notification.delivery.backoff', [10, 60, 300]);
    }

    public function handle(NotificationDeliveryServiceInterface $delivery): void
    {
        // O escopo global é fail-closed fora de contexto, e o worker não tem um.
        TenantContext::runWith((string) $this->log->tenant_id, fn () => $delivery->deliver($this->log));
    }

    public function failed(?Throwable $exception): void
    {
        TenantContext::runWith((string) $this->log->tenant_id, function () use ($exception): void {
            app(NotificationDeliveryServiceInterface::class)->fail(
                $this->log,
                $exception?->getMessage() ?: 'entrega esgotou as tentativas',
            );
        });
    }
}
