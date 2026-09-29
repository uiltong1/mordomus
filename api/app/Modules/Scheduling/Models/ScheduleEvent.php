<?php

namespace Mordomus\Scheduling\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Mordomus\Common\Eloquent\BelongsToTenant;

/**
 * Trilha imutável do que aconteceu com uma ocorrência.
 *
 * A linha é append-only: não há `created_at`/`updated_at` porque `occurred_at`
 * é o único relógio, e nada reescreve o registro depois de inserido. O mesmo
 * vale para `archived_at` — apagar a trilha seria falsificar histórico.
 *
 * `actor` é nulo quando o evento veio da fila, e `nullOnDelete` no banco
 * mantém a trilha legível depois que a conta some.
 */
#[Fillable([
    'tenant_id',
    'job_schedule_id',
    'event',
    'actor',
    'payload',
    'occurred_at',
])]
class ScheduleEvent extends Model
{
    use BelongsToTenant, HasUlids;

    public const CREATED = 'CREATED';

    public const NOTIFIED = 'NOTIFIED';

    public const COMPLETED = 'COMPLETED';

    public const SKIPPED = 'SKIPPED';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ScheduleEvent, JobSchedule, $this> */
    public function jobSchedule(): BelongsTo
    {
        return $this->belongsTo(JobSchedule::class, 'job_schedule_id');
    }
}
