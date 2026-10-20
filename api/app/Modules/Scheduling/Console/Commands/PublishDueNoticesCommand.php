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

    public function handle(DueNoticeServiceInterface $notices): int
    {
        $now = CarbonImmutable::now('UTC');
        $tenantIds = $this->tenantIds();
        $published = 0;

        foreach ($tenantIds as $tenantId) {

            $published += TenantContext::runWith(
                $tenantId,
                fn (): int => $notices->publish($tenantId, $now),
            );
        }

        $this->info(sprintf('%d aviso(s) publicado(s) em %d residência(s).', $published, count($tenantIds)));

        return self::SUCCESS;
    }
}
