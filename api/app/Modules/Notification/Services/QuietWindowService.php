<?php

declare(strict_types=1);

namespace Mordomus\Notification\Services;

use Carbon\CarbonImmutable;
use Mordomus\Notification\Contracts\Repositories\NotificationPreferenceRepositoryInterface;
use Mordomus\Notification\Contracts\Services\QuietWindowServiceInterface;
use Mordomus\Notification\Models\NotificationPreference;

final class QuietWindowService implements QuietWindowServiceInterface
{
    public function __construct(
        private readonly NotificationPreferenceRepositoryInterface $preferences,
    ) {}

    public function effective(string $tenantId, string $userId): NotificationPreference
    {
        $own = $this->preferences->findForUser($tenantId, $userId);
        $house = $this->preferences->findForHouse($tenantId);

        return $this->merge($own, $house, $this->fromEnvironment());
    }

    public function deliverAt(NotificationPreference $preference, CarbonImmutable $now, string $timezone): CarbonImmutable
    {
        $local = $now->timezone($timezone);

        if ($preference->isDaily()) {
            $slot = $this->dailySlot($preference, $local);

            return $this->isQuietAt($preference, $slot, $timezone) ? $this->afterQuiet($preference, $slot) : $slot;
        }

        return $this->isQuietAt($preference, $local, $timezone) ? $this->afterQuiet($preference, $local) : $local;
    }

    public function isQuietAt(NotificationPreference $preference, CarbonImmutable $now, string $timezone): bool
    {
        if ($preference->quiet_start === null || $preference->quiet_end === null) {
            return false;
        }

        $start = $preference->quietStartMinutes();
        $end = $preference->quietEndMinutes();
        $minutes = $this->minutesOf($now->timezone($timezone));

        if ($start === $end) {
            return false;
        }

        return $start < $end
            ? $minutes >= $start && $minutes < $end
            : $minutes >= $start || $minutes < $end;
    }

    private function merge(?NotificationPreference $own, ?NotificationPreference $house, NotificationPreference $environment): NotificationPreference
    {
        return new NotificationPreference([
            'quiet_start' => $own?->quiet_start ?? $house?->quiet_start ?? $environment->quiet_start,
            'quiet_end' => $own?->quiet_end ?? $house?->quiet_end ?? $environment->quiet_end,
            'digest' => $own?->digest ?? $house?->digest ?? $environment->digest,
            'preferred_hour' => $own?->preferred_hour ?? $house?->preferred_hour ?? $environment->preferred_hour,
        ]);
    }

    private function fromEnvironment(): NotificationPreference
    {
        return new NotificationPreference([
            'quiet_start' => (string) config('notification.defaults.quiet_start'),
            'quiet_end' => (string) config('notification.defaults.quiet_end'),
            'digest' => (string) config('notification.defaults.digest'),
            'preferred_hour' => (string) config('notification.defaults.preferred_hour'),
        ]);
    }

    private function dailySlot(NotificationPreference $preference, CarbonImmutable $local): CarbonImmutable
    {
        $hour = $preference->preferred_hour?->format('H:i') ?? '00:00';
        [$h, $m] = array_pad(explode(':', $hour), 2, '00');

        $slot = $local->setTime((int) $h, (int) $m, 0);

        return $slot->greaterThan($local) ? $slot : $slot->addDay();
    }

    private function afterQuiet(NotificationPreference $preference, CarbonImmutable $local): CarbonImmutable
    {
        $end = $local->setTimeFromTimeString(
            $preference->quiet_end?->format('H:i') ?? '00:00',
        );

        return $end->greaterThan($local) ? $end : $end->addDay();
    }

    private function minutesOf(CarbonImmutable $local): int
    {
        return $local->hour * 60 + $local->minute;
    }
}
