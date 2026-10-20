<?php

declare(strict_types=1);

namespace Mordomus\Notification\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Mordomus\Common\Eloquent\BelongsToTenant;
use Mordomus\Identity\Models\User;

#[Fillable([
    'tenant_id',
    'user_id',
    'quiet_start',
    'quiet_end',
    'digest',
    'preferred_hour',
])]
class NotificationPreference extends Model
{
    use BelongsToTenant, HasUlids;

    public const DIGEST_INSTANT = 'instant';

    public const DIGEST_DAILY = 'daily';

    public const DIGESTS = [self::DIGEST_INSTANT, self::DIGEST_DAILY];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'quiet_start' => 'datetime:H:i',
            'quiet_end' => 'datetime:H:i',
            'preferred_hour' => 'datetime:H:i',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isHouseDefault(): bool
    {
        return $this->user_id === null;
    }

    public function isDaily(): bool
    {
        return $this->digest === self::DIGEST_DAILY;
    }

    public function quietWindowWrapsMidnight(): bool
    {
        if ($this->quiet_start === null || $this->quiet_end === null) {
            return false;
        }

        return $this->quietStartMinutes() > $this->quietEndMinutes();
    }

    public function quietStartMinutes(): int
    {
        return $this->minutesOf($this->quiet_start);
    }

    public function quietEndMinutes(): int
    {
        return $this->minutesOf($this->quiet_end);
    }

    private function minutesOf(?\DateTimeInterface $value): int
    {
        return $value === null ? 0 : ((int) $value->format('H')) * 60 + (int) $value->format('i');
    }
}
