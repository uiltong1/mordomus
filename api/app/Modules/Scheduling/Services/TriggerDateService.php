<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Services;

use Carbon\CarbonImmutable;
use Mordomus\Scheduling\Contracts\Services\TriggerDateServiceInterface;
use Mordomus\Scheduling\Exceptions\IncompleteTriggerRule;
use Mordomus\Scheduling\Models\TriggerConfig;

/**
 * A matemática de recorrência: `nextDue` nos 4 tipos e o instante de disparo.
 *
 * Duas decisões que o código acima não explica sozinho:
 *
 * - `addMonthsNoOverflow` no intervalo em meses: 31 de janeiro mais um mês é
 *   28/29 de fevereiro, não 2 de março. Com overflow, a data de uma tarefa
 *   mensal pularia o mês e o ciclo nunca fecharia no dia esperado.
 * - `day_of_month` é preso ao último dia do mês (31 em fevereiro vira 28/29).
 *   Somar dias a partir do dia 1 transbordaria para o mês seguinte — a regra
 *   do dia 31 passaria a valer dia 3 de março.
 */
final class TriggerDateService implements TriggerDateServiceInterface
{
    public function nextDue(TriggerConfig $config, ?CarbonImmutable $base, string $timezone): ?CarbonImmutable
    {
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $anchor = $this->resolveAnchor($config, $base, $today, $timezone);

        return match ($config->type) {
            TriggerConfig::TYPE_INTERVAL => $this->addInterval($anchor, $config),
            TriggerConfig::TYPE_CALENDAR_MONTHLY => $this->onDayOfMonth($anchor, $config, $today),
            // Os dois medem a partir de um cumprimento: sem âncora não há data,
            // e a data precisa vir do registro concluído, não de um palpite.
            TriggerConfig::TYPE_POST_COMPLETION => $anchor === null
                ? null
                : $this->addInterval($anchor, $config),
            TriggerConfig::TYPE_ESCALATED => $anchor,
            default => throw IncompleteTriggerRule::make((string) $config->type, ['type']),
        };
    }

    public function dueAt(CarbonImmutable $scheduledFor, string $timezone, string $preferredHour): CarbonImmutable
    {
        [$hour, $minute] = $this->splitHour($preferredHour);

        return $scheduledFor->setTimezone($timezone)->setTime($hour, $minute)->utc();
    }

    /**
     * Âncora do ciclo: a base explícita quando existe, senão a data da última
     * base gravada, senão o que o tipo permitir.
     *
     * `INTERVAL` e `CALENDAR_MONTHLY` contam a partir de hoje, então nunca
     * ficam sem âncora. `POST_COMPLETION` e `ESCALATED` medem a partir de um
     * cumprimento que ainda não aconteceu — sem âncora eles devolvem `null`, e
     * quem chama decide o que fazer com uma regra que ainda não rodou.
     */
    private function resolveAnchor(TriggerConfig $config, ?CarbonImmutable $base, CarbonImmutable $today, string $timezone): ?CarbonImmutable
    {
        if ($base !== null) {
            return $base->setTimezone($timezone)->startOfDay();
        }

        $stored = $config->last_base_date;

        if ($stored !== null) {
            // Coluna `date` é dia de calendário, não instante: ler o `Y-m-d` e
            // remontar no fuso da residência evita o salto de um dia que a
            // conversão de fuso produziria.
            return CarbonImmutable::createFromFormat('Y-m-d', $stored->format('Y-m-d'), $timezone)->startOfDay();
        }

        return in_array($config->type, [
            TriggerConfig::TYPE_INTERVAL,
            TriggerConfig::TYPE_CALENDAR_MONTHLY,
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

        // Laço e não um `if`: a âncora pode estar vários meses no passado e
        // um único avanço ainda deixaria a data no passado.
        while ($candidate->isBefore($today)) {
            $month = $month->addMonthNoOverflow()->startOfMonth();
            $candidate = $this->dayIn($month, $dayOfMonth);
        }

        return $candidate;
    }

    /** Dia fixo do mês, preso ao último dia quando o mês é mais curto. */
    private function dayIn(CarbonImmutable $month, int $dayOfMonth): CarbonImmutable
    {
        return $month->day(min($dayOfMonth, $month->daysInMonth))->startOfDay();
    }

    /** @return array{0: int, 1: int} */
    private function splitHour(string $preferredHour): array
    {
        $parts = explode(':', $preferredHour);

        return [(int) ($parts[0] ?? 0), (int) ($parts[1] ?? 0)];
    }
}
