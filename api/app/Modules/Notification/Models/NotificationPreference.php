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
 * Quiet hours, digest e horário preferido — de um morador ou da casa.
 *
 * `user_id` nulo é a preferência da casa. A precedência (morador → casa →
 * padrão do ambiente) é do `QuietWindowService`, e cada lado pode existir uma
 * vez só porque o banco garante isso.
 *
 * A janela de silêncio **atravessa a meia-noite**: 22:00–07:00 é uma janela,
 * não duas. Quem grava a linha decide o horário, e quem lê precisa saber
 * quando `quiet_start` é maior que `quiet_end` — é o que o par significa.
 */
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

    /** Sai assim que pode, adiando para o fim da janela de silêncio. */
    public const DIGEST_INSTANT = 'instant';

    /** Espera o horário preferido e sai consolidado. */
    public const DIGEST_DAILY = 'daily';

    /** @var list<string> */
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

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Preferência da casa: vale para quem não configurou a sua. */
    public function isHouseDefault(): bool
    {
        return $this->user_id === null;
    }

    public function isDaily(): bool
    {
        return $this->digest === self::DIGEST_DAILY;
    }

    /**
     * A janela de silêncio atravessa a meia-noite.
     *
     * `quiet_start` maior que `quiet_end` é o caso normal de quem dorme: o
     * silêncio começa às 22:00 e acaba às 07:00 do dia seguinte. Início e fim
     * iguais não são janela — é ausência de janela, e tratar como silêncio
     * deixaria o morador sem notificação a vida inteira.
     */
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
