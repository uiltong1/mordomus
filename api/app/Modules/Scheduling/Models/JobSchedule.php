<?php

namespace Mordomus\Scheduling\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Mordomus\Common\Eloquent\BelongsToTenant;

#[Fillable([
    'tenant_id',
    'trigger_config_id',
    'scheduled_for',
    'due_at',
    'status',
    'notified_at',
    'completed_at',
    'completed_by',
])]
class JobSchedule extends Model
{
    use BelongsToTenant, HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_NOTIFIED = 'notified';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_OVERDUE = 'overdue';

    public const OPEN_STATUSES = [self::STATUS_PENDING, self::STATUS_NOTIFIED, self::STATUS_OVERDUE];

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_NOTIFIED,
        self::STATUS_COMPLETED,
        self::STATUS_SKIPPED,
        self::STATUS_OVERDUE,
    ];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'date',
            'due_at' => 'datetime',
            'notified_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function triggerConfig(): BelongsTo
    {
        return $this->belongsTo(TriggerConfig::class, 'trigger_config_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_SKIPPED], true);
    }
}
