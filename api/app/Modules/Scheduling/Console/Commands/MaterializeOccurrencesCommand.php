<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Console\Commands;

use Illuminate\Console\Command;
use Mordomus\Scheduling\Console\Concerns\EnumeratesTenants;
use Mordomus\Scheduling\Jobs\MaterializeTenantOccurrences;

class MaterializeOccurrencesCommand extends Command
{
    use EnumeratesTenants;

    /**
     * Job por residência, e não uma varredura única: o motor é particionável
     * por `tenant_id`, e materializar cada casa não segura o worker enquanto a
     * próxima é processada.
     */
    protected $signature = 'scheduling:materialize
                            {--days= : Janela de materialização em dias; sem ela vale config/scheduling.php}';

    protected $description = 'Enfileira a materialização das ocorrências de cada residência';

    public function handle(): int
    {
        $horizonDays = (int) ($this->option('days') ?: config('scheduling.horizon_days'));
        $queue = (string) config('scheduling.queues.occurrences');
        $tenantIds = $this->tenantIds();

        foreach ($tenantIds as $tenantId) {
            MaterializeTenantOccurrences::dispatch($tenantId, $horizonDays)->onQueue($queue);
        }

        $this->info(sprintf('Materialização de %d residência(s) enfileirada em %s.', count($tenantIds), $queue));

        return self::SUCCESS;
    }
}
