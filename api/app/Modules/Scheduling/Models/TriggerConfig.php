<?php

namespace Mordomus\Scheduling\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Mordomus\Common\Eloquent\BelongsToTenant;

#[Fillable([
    'tenant_id',
    'subject_type',
    'asset_id',
    'bill_id',
    'title',
    'description',
    'is_active',
    'type',
    'interval_value',
    'interval_unit',
    'day_of_month',
    'advance_notice_days',
    'recalculate_base',
    'custom_offsets',
    'preferred_hour',
    'last_base_date',
    'next_due_at',
])]
class TriggerConfig extends Model
{
    use BelongsToTenant, HasUlids;

    public const SUBJECT_ASSET = 'asset';

    public const SUBJECT_BILL = 'bill';

    public const TYPE_INTERVAL = 'INTERVAL';

    public const TYPE_CALENDAR_MONTHLY = 'CALENDAR_MONTHLY';

    public const TYPE_POST_COMPLETION = 'POST_COMPLETION';

    public const TYPE_ESCALATED = 'ESCALATED';

    public const TYPES = [
        self::TYPE_INTERVAL,
        self::TYPE_CALENDAR_MONTHLY,
        self::TYPE_POST_COMPLETION,
        self::TYPE_ESCALATED,
    ];

    public const SUBJECT_TYPES = [self::SUBJECT_ASSET, self::SUBJECT_BILL];

    public const INTERVAL_UNITS = ['days', 'weeks', 'months'];

    public const RECALCULATE_DUE_DATE = 'DUE_DATE';

    public const RECALCULATE_COMPLETION = 'COMPLETION';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'interval_value' => 'integer',
            'day_of_month' => 'integer',
            'advance_notice_days' => 'integer',
            'custom_offsets' => 'array',
            'preferred_hour' => 'string',
            'last_base_date' => 'date',
            'next_due_at' => 'datetime',
        ];
    }

    public function subjectId(): ?string
    {
        return $this->subject_type === self::SUBJECT_BILL ? $this->bill_id : $this->asset_id;
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }

    public function noticeOffsets(): array
    {
        if ($this->type === self::TYPE_ESCALATED) {
            $offsets = array_values($this->custom_offsets ?? []);
            sort($offsets);

            return array_map(intval(...), $offsets);
        }

        $notice = $this->advance_notice_days ?? 0;

        return $notice > 0 ? [-$notice] : [0];
    }

    public function noticeLeadDays(): int
    {
        $offsets = $this->noticeOffsets();

        return $offsets === [] ? 0 : max(0, -min($offsets));
    }

    public function noticeTailDays(): int
    {
        $offsets = $this->noticeOffsets();

        return $offsets === [] ? 0 : max(0, max($offsets));
    }

    public function getPreferredHourAttribute(?string $value): ?string
    {
        return $value === null ? null : substr($value, 0, 5);
    }
}
