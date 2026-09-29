<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Contracts\Services;

/**
 * Recálculo do ciclo depois do check-in.
 *
 * É o caminho que transforma a conclusão em próxima data: roda na fila porque
 * o morador não pode esperar o cálculo do motor para ver o card responder.
 */
interface OccurrenceRecalculatorServiceInterface
{
    public function recalculate(string $tenantId, string $occurrenceId): void;
}
