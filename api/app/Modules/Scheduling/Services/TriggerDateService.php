<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Services;

use Carbon\CarbonImmutable;
use Mordomus\Scheduling\Contracts\Services\TriggerDateServiceInterface;
use Mordomus\Scheduling\Exceptions\IncompleteTriggerRule;
use Mordomus\Scheduling\Models\TriggerConfig;

final class TriggerDateService implements TriggerDateServiceInterface
{
    public function nextDue(TriggerConfig $config, ?CarbonImmutable $base, string $timezone): ?CarbonImmutable
    {
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $anchor = $this->resolveAnchor($config, $base, $today, $timezone);

        return match ($config->type) {
            TriggerConfig::TYPE_INTERVAL => $this->addInterval($anchor, $config),
            TriggerConfig::TYPE_CALENDAR_MONTHLY => $this->onDayOfMonth($anchor, $config, $today),

            TriggerConfig::TYPE_POST_COMPLETION, TriggerConfig::TYPE_ESCALATED => $anchor === null
                ? null
                : $this->addInterval($anchor, $config),
            default => throw IncompleteTriggerRule::make((string) $config->type, ['type']),
        };
    }

    public function dueAt(CarbonImmutable $scheduledFor, string $timezone, string $preferredHour): CarbonImmutable
    {
        [$hour, $minute] = $this->splitHour($preferredHour);

        return $scheduledFor->setTimezone($timezone)->setTime($hour, $minute)->utc();
    }

    private function resolveAnchor(TriggerConfig $config, ?CarbonImmutable $base, CarbonImmutable $today, string $timezone): ?CarbonImmutable
    {
        if ($base !== null) {
            return $base->setTimezone($timezone)->startOfDay();
        }

        $stored = $config->last_base_date;

        if ($stored !== null) {

            return CarbonImmutable::createFromFormat('Y-m-d', $stored->format('Y-m-d'), $timezone)->startOfDay();
        }

        return in_array($config->type, [
            TriggerConfig::TYPE_INTERVAL,
            TriggerConfig::TYPE_CALENDAR_MONTHLY,
            TriggerConfig::TYPE_ESCALATED,
        ], true) ? $today : null;
    }

    private function addInterval(?CarbonImmutable $from, TriggerConfig $config): CarbonImmutable
    {
        $value = $config->interval_value;
        $unit = $config->interval_unit;

        if ($value === null || $unit === null) {
            throw IncompleteTriggerRule::make(
                (string) $config->type,
                array_values(array_filter(['interval_value', 'interval_unit'], fn (string $field): bool => $config->{$field} === null)),
            );
        }

        return match ($unit) {
            'days' => $from->addDays($value),
            'weeks' => $from->addWeeks($value),
            'months' => $from->addMonthsNoOverflow($value),
            default => throw IncompleteTriggerRule::make((string) $config->type, ['interval_unit']),
        };
    }

    private function onDayOfMonth(?CarbonImmutable $from, TriggerConfig $config, CarbonImmutable $today): CarbonImmutable
    {
        $dayOfMonth = $config->day_of_month;

        if ($dayOfMonth === null) {
            throw IncompleteTriggerRule::make((string) $config->type, ['day_of_month']);
        }

        $month = $from->startOfMonth();
        $candidate = $this->dayIn($month, $dayOfMonth);

        while ($candidate->isBefore($today)) {
            $month = $month->addMonthNoOverflow()->startOfMonth();
            $candidate = $this->dayIn($month, $dayOfMonth);
        }

        return $candidate;
    }

    private function dayIn(CarbonImmutable $month, int $dayOfMonth): CarbonImmutable
    {
        return $month->day(min($dayOfMonth, $month->daysInMonth))->startOfDay();
    }

    private function splitHour(string $preferredHour): array
    {
        $parts = explode(':', $preferredHour);

        return [(int) ($parts[0] ?? 0), (int) ($parts[1] ?? 0)];
    }
}
