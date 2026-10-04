<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Console\Commands;

use Illuminate\Console\Command;
use Mordomus\Scheduling\Models\JobSchedule;
use Carbon\CarbonImmutable;

class PruneJobSchedulesCommand extends Command
{
    protected $signature = 'scheduling:prune {--days=30 : Janela de retenção em dias}';
    protected $description = 'Remove ocorrências de agendamento antigas';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $before = CarbonImmutable::now()->subDays($days);

        // JobSchedule.due_at é o instante do disparo.
        // ScheduleEvent possui FK com cascadeOnDelete para JobSchedule.
        $count = JobSchedule::where('due_at', '<', $before)->delete();
        
        $this->info("Removidas $count ocorrências de agendamento antigas.");
        
        return self::SUCCESS;
    }
}
