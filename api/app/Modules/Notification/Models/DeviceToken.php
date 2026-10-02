<?php

declare(strict_types=1);

namespace Mordomus\Notification\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Mordomus\Common\Eloquent\BelongsToTenant;
use Mordomus\Identity\Models\User;

/**
 * Assinatura de Web Push de um morador (ADR-001).
 *
 * A linha é uma assinatura, não um aparelho: o mesmo celular pode assinar em
 * dois navegadores, e trocar de navegador não deve apagar o histórico. O
 * `endpoint` é a chave natural — é por ele que a assinatura morta é
 * reconhecida na resposta do serviço de push e removida.
 */
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

    /** Navegador com Web Push — o canal do ADR-001. */
    public const PLATFORM_WEB = 'web';

    /** Reservado ao adapter de app nativo (FCM). */
    public const PLATFORM_ANDROID = 'android';

    /** Reservado ao adapter de app nativo (APNs). */
    public const PLATFORM_IOS = 'ios';

    /** @var list<string> */
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

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isWeb(): bool
    {
        return $this->platform === self::PLATFORM_WEB;
    }
}
