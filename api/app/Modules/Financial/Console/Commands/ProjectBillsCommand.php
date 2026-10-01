<?php

declare(strict_types=1);

namespace Mordomus\Financial\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Mordomus\Common\Console\EnumeratesTenants;
use Mordomus\Common\Support\TenantContext;
use Mordomus\Financial\Contracts\Services\BillScheduleConsumerServiceInterface;
use Mordomus\Scheduling\Contracts\Services\TenantCalendarServiceInterface;

/**
 * Projeção dos vencimentos da residência: reconcilia o que o motor materializou
 * e marca o que já venceu.
 *
 * Roda no processo do scheduler, sem fila no meio: a passada é uma leitura por
 * residência e uma escrita pequena, e passar pela fila transformaria uma
 * varredura de 15 minutos em N tarefas para reencontrar as mesmas linhas.
 */
class ProjectBillsCommand extends Command
{
    use EnumeratesTenants;

    protected $signature = 'financial:project';

    protected $description = 'Refaz os vencimentos a partir da agenda do Scheduling e marca os atrasados';

    public function handle(
        BillScheduleConsumerServiceInterface $consumer,
        TenantCalendarServiceInterface $calendar,
    ): int {
        $tenantIds = $this->tenantIds();
        $created = 0;
        $overdue = 0;

        foreach ($tenantIds as $tenantId) {
            $today = CarbonImmutable::now($calendar->timezone($tenantId))->format('Y-m-d');

            // O escopo global do Eloquent é fail-closed fora de contexto: sem
            // esta moldura a varredura leria zero linhas de todo tenant.
            [$createdNow, $overdueNow] = TenantContext::runWith(
                $tenantId,
                fn (): array => [
                    $consumer->projectDueDates($tenantId),
                    $consumer->markOverdue($tenantId, $today),
                ],
            );

            $created += $createdNow;
            $overdue += $overdueNow;
        }

        $this->info(sprintf(
            '%d vencimento(s) criado(s) e %d marcado(s) como atrasado(s) em %d residência(s).',
            $created,
            $overdue,
            count($tenantIds),
        ));

        return self::SUCCESS;
    }
}
