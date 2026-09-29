<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Console\Concerns;

use Mordomus\Identity\Models\Tenant;

/**
 * As residências que o motor varre.
 *
 * Leitura da tabela do Identity: a fronteira permite leitura de outro módulo
 * (TECHSPEC §5), e a escrita de `job_schedules` continua sendo só do
 * Scheduling. Arquivada não entra — casa arquivada não gera aviso nem
 * occurrence nova.
 */
trait EnumeratesTenants
{
    /** @return list<string> */
    private function tenantIds(): array
    {
        return array_map(
            strval(...),
            Tenant::query()
                ->whereNull('archived_at')
                ->orderBy('id')
                ->pluck('id')
                ->all(),
        );
    }
}
