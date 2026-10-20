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

class DeliverNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public array $backoff;

    public function __construct(public readonly NotificationLog $log)
    {
        $this->tries = (int) config('notification.delivery.tries', 3);
        $this->backoff = (array) config('notification.delivery.backoff', [10, 60, 300]);
    }

    public function handle(NotificationDeliveryServiceInterface $delivery): void
    {

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
