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
 * Trilha do que foi notificado, para quem e com que resultado.
 *
 * A linha nasce `queued` — a intenção, com o instante em que *pode* sair já
 * decidido pelas quiet hours — e vira `sent` ou `failed` quando o canal
 * responde. `available_at` é o motivo de o adiamento ser visível: um aviso que
 * saiu às 07:00 porque às 23:00 o morador estava dormindo precisa deixar
 * rastro de quando era para ter saído.
 *
 * `dedupe_key` é único e é o que segura o at-least-once: a mesma mensagem
 * redelegada tenta a mesma chave e o banco recusa a segunda linha.
 */
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

    /** Fallback in-app: o que o sino mostra quando não há push. */
    public const CHANNEL_INAPP = 'inapp';

    /** @var list<string> */
    public const CHANNELS = [self::CHANNEL_PUSH, self::CHANNEL_EMAIL, self::CHANNEL_INAPP];

    /** Intenção registrada, ainda não entregue. */
    public const STATUS_QUEUED = 'queued';

    /** O canal respondeu que entregou. */
    public const STATUS_SENT = 'sent';

    /** O canal recusou ou esgotou as tentativas. */
    public const STATUS_FAILED = 'failed';

    /** @var list<string> */
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

    /** @return BelongsTo<User, $this> */
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
