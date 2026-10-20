<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Mordomus\Scheduling\Events\QueuedEnvelope;
use Throwable;

class PublishEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    public function __construct(public readonly array $envelope) {}

    public function handle(): void
    {
        event(new QueuedEnvelope($this->envelope));
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('scheduling.event_failed', [
            'event' => $this->envelope['event'] ?? null,
            'event_id' => $this->envelope['event_id'] ?? null,
            'tenant_id' => $this->envelope['tenant_id'] ?? null,
            'exception' => $exception,
            'dead_lettered_at' => CarbonImmutable::now('UTC')->toIso8601String(),
        ]);
    }
}
