<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Mordomus\Common\Console\EnumeratesTenants;
use Mordomus\Common\Support\TenantContext;
use Mordomus\Scheduling\Contracts\Services\DueNoticeServiceInterface;

class PublishDueNoticesCommand extends Command
{
    use EnumeratesTenants;

    protected $signature = 'scheduling:publish-due';

    protected $description = 'Publica schedule.due para os avisos vencidos e marca as ocorrências atrasadas';

    /**
     * Roda no processo do scheduler, sem fila no meio.
     *
     * O varrimento é uma leitura grande e uma escrita pequena por ocorrência —
     * passar pela fila transformaria uma passada de 15 minutos em N tarefas
     * para reencontrar o mesmo conjunto de linhas. A fila entra depois, na
     * publicação de cada aviso.
     */
    public function handle(DueNoticeServiceInterface $notices): int
    {
        $now = CarbonImmutable::now('UTC');
        $tenantIds = $this->tenantIds();
        $published = 0;

        foreach ($tenantIds as $tenantId) {
            // O escopo global do Eloquent é fail-closed fora de contexto: sem
            // esta moldura a varredura leria zero linhas de todo tenant.
            $published += TenantContext::runWith(
                $tenantId,
                fn (): int => $notices->publish($tenantId, $now),
            );
        }

        $this->info(sprintf('%d aviso(s) publicado(s) em %d residência(s).', $published, count($tenantIds)));

        return self::SUCCESS;
    }
}
