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
    'platform',
    'endpoint',
    'p256dh',
    'auth',
    'fcm_token',
    'last_seen_at',
])]
class DeviceToken extends Model
{
    use BelongsToTenant, HasUlids;

    public const PLATFORM_WEB = 'web';

    public const PLATFORM_ANDROID = 'android';

    public const PLATFORM_IOS = 'ios';

    public const PLATFORMS = [
        self::PLATFORM_WEB,
        self::PLATFORM_ANDROID,
        self::PLATFORM_IOS,
    ];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $hidden = ['p256dh', 'auth', 'fcm_token'];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isWeb(): bool
    {
        return $this->platform === self::PLATFORM_WEB;
    }
}
