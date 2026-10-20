<?php

namespace Mordomus\Financial\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Mordomus\Common\Eloquent\BelongsToTenant;

#[Fillable([
    'tenant_id',
    'bill_id',
    'schedule_id',
    'due_date',
    'amount',
    'status',
    'paid_at',
    'paid_by',
])]
class BillOccurrence extends Model
{
    use BelongsToTenant, HasUlids;

    public const STATUS_OPEN = 'open';

    public const STATUS_PAID = 'paid';

    public const STATUS_OVERDUE = 'overdue';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_PAID,
        self::STATUS_OVERDUE,
        self::STATUS_CANCELLED,
    ];

    public const PAYABLE_STATUSES = [self::STATUS_OPEN, self::STATUS_OVERDUE];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function dueDate(): string
    {
        return CarbonImmutable::parse($this->due_date)->format('Y-m-d');
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PaymentRecord::class);
    }

    public function splitResults(): HasMany
    {
        return $this->hasMany(SplitResult::class);
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isPayable(): bool
    {
        return in_array($this->status, self::PAYABLE_STATUSES, true);
    }

    public function isFromSchedule(): bool
    {
        return $this->schedule_id !== null;
    }
}
