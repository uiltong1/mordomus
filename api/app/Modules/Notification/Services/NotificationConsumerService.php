<?php

declare(strict_types=1);

namespace Mordomus\Notification\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Mordomus\Common\Support\TenantContext;
use Mordomus\Financial\Events\EventName as FinancialEventName;
use Mordomus\Identity\Contracts\Repositories\MembershipRepositoryInterface;
use Mordomus\Identity\Events\EventName as IdentityEventName;
use Mordomus\Notification\Contracts\Repositories\DeviceTokenRepositoryInterface;
use Mordomus\Notification\Contracts\Repositories\NotificationLogRepositoryInterface;
use Mordomus\Notification\Contracts\Repositories\NotificationPreferenceRepositoryInterface;
use Mordomus\Notification\Contracts\Services\NotificationConsumerServiceInterface;
use Mordomus\Notification\Contracts\Services\NotificationMessageServiceInterface;
use Mordomus\Notification\Contracts\Services\QuietWindowServiceInterface;
use Mordomus\Notification\Jobs\DeliverNotification;
use Mordomus\Notification\Models\NotificationLog;
use Mordomus\Scheduling\Contracts\Services\TenantCalendarServiceInterface;
use Mordomus\Scheduling\Events\EventName as ScheduleEventName;

final class NotificationConsumerService implements NotificationConsumerServiceInterface
{
    public function __construct(
        private readonly NotificationLogRepositoryInterface $logs,
        private readonly DeviceTokenRepositoryInterface $devices,
        private readonly NotificationPreferenceRepositoryInterface $preferences,
        private readonly MembershipRepositoryInterface $memberships,
        private readonly NotificationMessageServiceInterface $messages,
        private readonly QuietWindowServiceInterface $quiet,
        private readonly TenantCalendarServiceInterface $calendar,
    ) {}

    public function consume(array $envelope): int
    {
        $event = $this->string($envelope, 'event');
        $tenantId = $this->string($envelope, 'tenant_id');
        $payload = (array) ($envelope['payload'] ?? []);

        if ($event === '' || $tenantId === '') {
            Log::warning('notification.envelope_malformed', [
                'event' => $event !== '' ? $event : null,
                'envelope_keys' => array_keys($envelope),
            ]);

            return 0;
        }

        return TenantContext::runWith($tenantId, fn (): int => $this->deliverToRecipients($event, $tenantId, $payload));
    }

    private function deliverToRecipients(string $event, string $tenantId, array $payload): int
    {
        $queued = 0;
        $duplicated = 0;
        $withoutChannel = 0;

        foreach ($this->recipients($event, $tenantId, $payload) as $recipientId) {
            $message = $this->messages->render($event, $payload, $tenantId, $recipientId);

            if ($message === null) {
                continue;
            }

            $preference = $this->quiet->effective($tenantId, $recipientId);
            $channel = $preference->isDaily()
                ? NotificationLog::CHANNEL_EMAIL
                : NotificationLog::CHANNEL_PUSH;

            if ($channel === NotificationLog::CHANNEL_PUSH && $this->devices->forUser($tenantId, $recipientId)->isEmpty()) {
                $withoutChannel++;

                continue;
            }

            $availableAt = $this->quiet->deliverAt(
                $preference,
                CarbonImmutable::now('UTC'),
                $this->calendar->timezone($tenantId),
            );

            $log = $this->queue($event, $tenantId, $payload, $recipientId, $channel, $availableAt, $message);

            if ($log === null) {
                $duplicated++;

                continue;
            }

            $this->dispatch($log, $availableAt);
            $queued++;
        }

        if ($queued > 0 || $duplicated > 0 || $withoutChannel > 0) {
            Log::info('notification.envelope_consumed', [
                'tenant_id' => $tenantId,
                'event' => $event,
                'queued' => $queued,
                'duplicated' => $duplicated,
                'without_channel' => $withoutChannel,
            ]);
        }

        return $queued;
    }

    private function recipients(string $event, string $tenantId, array $payload): array
    {
        return match ($event) {

            ScheduleEventName::SCHEDULE_DUE, FinancialEventName::BILL_DUE => $this->activeMembers($tenantId),

            FinancialEventName::EXPENSE_SPLIT_COMPUTED => $this->shareHolders($payload),
            IdentityEventName::MEMBER_ADDED => $this->named([$this->string($payload, 'user_id')]),
            default => [],
        };
    }

    private function activeMembers(string $tenantId): array
    {
        return $this->memberships->listActiveForTenant($tenantId)
            ->map(fn ($membership): string => (string) $membership->user_id)
            ->values()
            ->all();
    }

    private function shareHolders(array $payload): array
    {
        $holders = [];

        foreach ((array) ($payload['shares'] ?? []) as $share) {
            if (is_array($share) && is_string($share['user_id'] ?? null) && $share['user_id'] !== '') {
                $holders[] = $share['user_id'];
            }
        }

        return $this->named($holders);
    }

    private function named(array $userIds): array
    {
        return array_values(array_unique(array_filter($userIds, fn (string $id): bool => $id !== '')));
    }

    private function queue(
        string $event,
        string $tenantId,
        array $payload,
        string $recipientId,
        string $channel,
        CarbonImmutable $availableAt,
        array $message,
    ): ?NotificationLog {
        try {
            return $this->logs->create([
                'tenant_id' => $tenantId,
                'user_id' => $recipientId,
                'channel' => $channel,
                'template' => $message['template'],
                'subject' => mb_substr($message['subject'], 0, 200),
                'body' => $message['body'],
                'dedupe_key' => $this->dedupeKey($event, $payload, $channel, $recipientId),
                'status' => NotificationLog::STATUS_QUEUED,
                'available_at' => $availableAt->utc()->toIso8601String(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    private function dedupeKey(string $event, array $payload, string $channel, string $recipientId): string
    {
        $subject = $this->string($payload, 'dedupe_key');

        if ($subject === '') {
            $subject = substr(hash('sha256', (string) json_encode($payload)), 0, 32);
        }

        return sprintf('%s:%s:%s:%s', $event, $subject, $channel, $recipientId);
    }

    private function dispatch(NotificationLog $log, CarbonImmutable $availableAt): void
    {

        $delay = CarbonImmutable::now('UTC')->diffInSeconds($availableAt);
        $queue = $log->isPush()
            ? (string) config('notification.queues.push')
            : (string) config('notification.queues.emails');

        DeliverNotification::dispatch($log)
            ->onQueue($queue)
            ->delay(max(0, (int) $delay));
    }

    private function string(array $source, string $key): string
    {
        $value = $source[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }
}
