<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Mordomus\Scheduling\Contracts\Services\OccurrenceRecalculatorServiceInterface;
use Mordomus\Scheduling\Jobs\Concerns\RunsInsideTenant;
use Throwable;

class RecalculateNextOccurrence implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInsideTenant, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public array $backoff = [10, 60, 300];

    public function __construct(
        public readonly string $tenantId,
        public readonly string $occurrenceId,
        public readonly string $scheduledFor,
    ) {}

    public function uniqueId(): string
    {
        return "tenant:{$this->tenantId}:{$this->occurrenceId}:{$this->scheduledFor}";
    }

    public function handle(OccurrenceRecalculatorServiceInterface $recalculator): void
    {
        $this->insideTenant(
            fn (): mixed => $recalculator->recalculate($this->tenantId, $this->occurrenceId),
        );
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('scheduling.recalculate_failed', [
            'tenant_id' => $this->tenantId,
            'occurrence_id' => $this->occurrenceId,
            'scheduled_for' => $this->scheduledFor,
            'exception' => $exception,
            'dead_lettered_at' => CarbonImmutable::now('UTC')->toIso8601String(),
        ]);
    }
}
