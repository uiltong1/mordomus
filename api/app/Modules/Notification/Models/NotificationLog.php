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
    'channel',
    'template',
    'subject',
    'body',
    'dedupe_key',
    'status',
    'available_at',
    'sent_at',
    'error',
])]
class NotificationLog extends Model
{
    use BelongsToTenant, HasUlids;

    public const CHANNEL_PUSH = 'push';

    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_INAPP = 'inapp';

    public const CHANNELS = [self::CHANNEL_PUSH, self::CHANNEL_EMAIL, self::CHANNEL_INAPP];

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [self::STATUS_QUEUED, self::STATUS_SENT, self::STATUS_FAILED];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'body' => 'array',
            'available_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isQueued(): bool
    {
        return $this->status === self::STATUS_QUEUED;
    }

    public function isPush(): bool
    {
        return $this->channel === self::CHANNEL_PUSH;
    }
}
