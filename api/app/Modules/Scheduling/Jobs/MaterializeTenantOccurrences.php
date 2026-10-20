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
use Illuminate\Queue\UniqueJobLockReleaseException;
use Illuminate\Support\Facades\Log;
use Mordomus\Scheduling\Contracts\Services\OccurrenceMaterializerServiceInterface;
use Mordomus\Scheduling\Jobs\Concerns\RunsInsideTenant;
use Throwable;

class MaterializeTenantOccurrences implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInsideTenant, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public array $backoff = [30, 120, 600];

    public function __construct(
        public readonly string $tenantId,
        public readonly int $horizonDays,
    ) {}

    public function uniqueId(): string
    {
        return "tenant:{$this->tenantId}:materialize";
    }

    public function handle(OccurrenceMaterializerServiceInterface $materializer): void
    {
        $this->insideTenant(
            fn (): int => $materializer->materialize($this->tenantId, $this->horizonDays),
        );
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception instanceof UniqueJobLockReleaseException) {
            Log::warning('scheduling.materialize_unique_collision', [
                'tenant_id' => $this->tenantId,
            ]);
        }

        Log::error('scheduling.materialize_failed', [
            'tenant_id' => $this->tenantId,
            'horizon_days' => $this->horizonDays,
            'exception' => $exception,
            'dead_lettered_at' => CarbonImmutable::now('UTC')->toIso8601String(),
        ]);
    }
}
