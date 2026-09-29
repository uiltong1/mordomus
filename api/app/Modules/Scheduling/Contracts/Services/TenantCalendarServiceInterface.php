<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Contracts\Services;

/**
 * Os fatos de calendário da residência: fuso e horário preferido.
 *
 * Separado do cálculo de data para que a matemática continue pura e o fuso
 * continue having um único dono — o mesmo fuso que a API usa para gravar e
 * devolver as datas.
 */
interface TenantCalendarServiceInterface
{
    public function timezone(string $tenantId): string;

    /** Config → residência → 09:00. */
    public function preferredHour(?string $configHour, string $tenantId): string;
}
