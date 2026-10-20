<?php

namespace Mordomus\Scheduling\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Mordomus\Common\Eloquent\BelongsToTenant;

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

    public function jobSchedule(): BelongsTo
    {
        return $this->belongsTo(JobSchedule::class, 'job_schedule_id');
    }
}
