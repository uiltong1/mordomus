<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Mordomus\Identity\Models\Tenant;
use Mordomus\Scheduling\Contracts\Services\TenantCalendarServiceInterface;

/**
 * Fuso e horário preferido da residência, lidos do módulo Identity.
 *
 * Sem estado entre chamadas: um fuso memorizado sobreviveria entre requisições
 * do mesmo processo e serviria a residência errada.
 */
final class TenantCalendarService implements TenantCalendarServiceInterface
{
    private const DEFAULT_TIMEZONE = 'America/Sao_Paulo';

    private const DEFAULT_HOUR = '09:00';

    public function timezone(string $tenantId): string
    {
        return $this->tenant($tenantId)?->timezone ?: self::DEFAULT_TIMEZONE;
    }

    public function preferredHour(?string $configHour, string $tenantId): string
    {
        $hour = $configHour ?: $this->tenant($tenantId)?->preferred_hour;

        return $this->normalize($hour);
    }

    public function day(CarbonInterface|string $day, string $tenantId): CarbonImmutable
    {
        $calendar = $day instanceof CarbonInterface ? $day->format('Y-m-d') : $day;

        return CarbonImmutable::createFromFormat('Y-m-d', $calendar, $this->timezone($tenantId))->startOfDay();
    }

    private function tenant(string $tenantId): ?Tenant
    {
        return Tenant::query()->find($tenantId);
    }

    /**
     * `time` no PostgreSQL chega como `HH:MM:SS` e a regra aceita `HH:MM`; o
     * prefixo de 5 caracteres deixa as duas formas no mesmo formato.
     */
    private function normalize(?string $hour): string
    {
        if ($hour === null || trim($hour) === '') {
            return self::DEFAULT_HOUR;
        }

        return substr(trim($hour), 0, 5);
    }
}
