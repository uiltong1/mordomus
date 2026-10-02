<?php

declare(strict_types=1);

namespace Mordomus\Notification\Contracts\Services;

use Carbon\CarbonImmutable;
use Mordomus\Notification\Models\NotificationPreference;

interface QuietWindowServiceInterface
{
    /**
     * Preferência que vale para o morador: a dele, a da casa ou o padrão do
     * ambiente.
     *
     * A precedência é de campo a campo e não de linha: quem configurou só a
     * janela de silêncio e deixou o digest no padrão continua com o digest do
     * ambiente, e não com o digest de outra pessoa.
     */
    public function effective(string $tenantId, string $userId): NotificationPreference;

    /**
     * Instante em que a notificação pode sair.
     *
     * Dentro da janela de silêncio, ou no modo `daily`, a resposta é o
     * adiamento — nunca "não envia".
     */
    public function deliverAt(NotificationPreference $preference, CarbonImmutable $now, string $timezone): CarbonImmutable;

    /** O adiamento foi por causa da janela de silêncio (e não do digest)? */
    public function isQuietAt(NotificationPreference $preference, CarbonImmutable $now, string $timezone): bool;
}
