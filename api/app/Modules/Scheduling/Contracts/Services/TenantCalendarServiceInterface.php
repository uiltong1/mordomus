<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Contracts\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

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

    /**
     * Dia de calendário (`Y-m-d` ou instante) às 00:00 no fuso da residência.
     *
     * Existe porque coluna `date` do PostgreSQL não é instante: um dia lido
     * como Carbon cai em UTC à meia-noite, e converter isso para um fuso atrás
     * devolve o dia anterior — o ciclo inteiro andaria um dia para trás.
     */
    public function day(CarbonInterface|string $day, string $tenantId): CarbonImmutable;
}
