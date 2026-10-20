<?php

declare(strict_types=1);

namespace Mordomus\Notification\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Mordomus\Identity\Models\User;
use Mordomus\Notification\Contracts\Repositories\DeviceTokenRepositoryInterface;
use Mordomus\Notification\Contracts\Repositories\NotificationLogRepositoryInterface;
use Mordomus\Notification\Contracts\Services\NotificationDeliveryServiceInterface;
use Mordomus\Notification\Contracts\Services\PushChannelInterface;
use Mordomus\Notification\Jobs\DeliverNotification;
use Mordomus\Notification\Mail\NotificationMail;
use Mordomus\Notification\Models\NotificationLog;

final class NotificationDeliveryService implements NotificationDeliveryServiceInterface
{
    public function __construct(
        private readonly NotificationLogRepositoryInterface $logs,
        private readonly DeviceTokenRepositoryInterface $devices,
        private readonly PushChannelInterface $push,
    ) {}

    public function deliver(NotificationLog $log): void
    {
        if (! $log->isQueued()) {
            return;
        }

        $wait = $this->secondsUntil($log);

        if ($wait > 0) {
            $this->requeue($log, $wait);

            return;
        }

        match ($log->channel) {
            NotificationLog::CHANNEL_PUSH => $this->push($log),
            NotificationLog::CHANNEL_EMAIL => $this->email($log),
            default => $this->fail($log, sprintf('Canal desconhecido: %s', $log->channel)),
        };
    }

    private function push(NotificationLog $log): void
    {
        $devices = $this->devices->forUser((string) $log->tenant_id, (string) $log->user_id);

        foreach ($devices as $device) {
            if (! $this->push->send($device, (array) $log->body)) {
                $this->devices->forget(
                    (string) $log->tenant_id,
                    (string) $log->user_id,
                    (string) $device->endpoint,
                );
            }
        }

        $this->logs->change($log, [
            'status' => NotificationLog::STATUS_SENT,
            'sent_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'error' => null,
        ]);
    }

    private function email(NotificationLog $log): void
    {
        $user = User::query()->find($log->user_id);

        if ($user === null) {
            $this->fail($log, 'O morador do aviso não existe mais.');

            return;
        }

        Mail::to($user->email)->send(new NotificationMail($log));

        $this->logs->change($log, [
            'status' => NotificationLog::STATUS_SENT,
            'sent_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'error' => null,
        ]);
    }

    private function requeue(NotificationLog $log, int $seconds): void
    {
        Log::info('notification.delivery_deferred', [
            'tenant_id' => $log->tenant_id,
            'user_id' => $log->user_id,
            'notification_log_id' => $log->id,
            'deferred_seconds' => $seconds,
        ]);

        DeliverNotification::dispatch($log)
            ->onQueue($this->queueOf($log))
            ->delay($seconds);
    }

    public function fail(NotificationLog $log, string $reason): void
    {
        Log::error('notification.delivery_failed', [
            'tenant_id' => $log->tenant_id,
            'user_id' => $log->user_id,
            'notification_log_id' => $log->id,
            'channel' => $log->channel,
            'template' => $log->template,
            'reason' => $reason,
        ]);

        $this->logs->change($log, [
            'status' => NotificationLog::STATUS_FAILED,
            'error' => mb_substr($reason, 0, 2000),
        ]);
    }

    private function secondsUntil(NotificationLog $log): int
    {
        if ($log->available_at === null) {
            return 0;
        }

        return max(0, (int) CarbonImmutable::now('UTC')->diffInSeconds($log->available_at));
    }

    private function queueOf(NotificationLog $log): string
    {
        return $log->isPush()
            ? (string) config('notification.queues.push')
            : (string) config('notification.queues.emails');
    }
}
