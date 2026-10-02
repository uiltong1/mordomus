<?php

declare(strict_types=1);

namespace Mordomus\Notification\Services;

use Carbon\CarbonImmutable;
use Mordomus\Notification\Contracts\Repositories\NotificationPreferenceRepositoryInterface;
use Mordomus\Notification\Contracts\Services\QuietWindowServiceInterface;
use Mordomus\Notification\Models\NotificationPreference;

/**
 * Quiet hours, digest e horário preferido: quando a notificação pode sair.
 *
 * A regra do módulo é que a janela de silêncio **adia, nunca cancela**. A
 * alternativa — descartar o que chegou durante a noite — é a que faz o morador
 * acordar sem nunca ter visto o aviso, e é por isso que a resposta é sempre um
 * instante, nunca um veto.
 *
 * Três decisões que o código acima não explica sozinho:
 *
 * - **A janela atravessa a meia-noite.** 22:00–07:00 é uma janela só, e quem
 *   dorme é o caso comum. `quiet_start` maior que `quiet_end` é o sinal; a
 *   checagem é `agora >= início OU agora < fim`, e o fim procurado é o do dia
 *   seguinte quando a noite começou.
 *
 * - **A precedência é de campo a campo.** Quem configurou só a janela e deixou
 *   o resto no padrão continua com o digest do ambiente. A linha inteira
 *   venceria a casa inteira por causa de um campo, e o morador que silenced a
 *   noite acharia que também trocou o digest sem ter trocado.
 *
 * - **O digest diário respeita a janela de silêncio.** O horário preferido cai
 *   dentro de 23:00–06:00 numa casa que preferiu assim, e sair às 23:00 seria
 *   desobedecer o morador que acabou de configurar a casa. Por isso o
 *   adiamento do digest passa uma vez mais pela janela.
 */
final class QuietWindowService implements QuietWindowServiceInterface
{
    public function __construct(
        private readonly NotificationPreferenceRepositoryInterface $preferences,
    ) {}

    public function effective(string $tenantId, string $userId): NotificationPreference
    {
        $own = $this->preferences->findForUser($tenantId, $userId);
        $house = $this->preferences->findForHouse($tenantId);

        return $this->merge($own, $house, $this->fromEnvironment());
    }

    public function deliverAt(NotificationPreference $preference, CarbonImmutable $now, string $timezone): CarbonImmutable
    {
        $local = $now->timezone($timezone);

        if ($preference->isDaily()) {
            $slot = $this->dailySlot($preference, $local);

            return $this->isQuietAt($preference, $slot, $timezone) ? $this->afterQuiet($preference, $slot) : $slot;
        }

        return $this->isQuietAt($preference, $local, $timezone) ? $this->afterQuiet($preference, $local) : $local;
    }

    public function isQuietAt(NotificationPreference $preference, CarbonImmutable $now, string $timezone): bool
    {
        if ($preference->quiet_start === null || $preference->quiet_end === null) {
            return false;
        }

        $start = $preference->quietStartMinutes();
        $end = $preference->quietEndMinutes();
        $minutes = $this->minutesOf($now->timezone($timezone));

        // Início e fim iguais é ausência de janela, e não silêncio de 24 h:
        // tratar como silêncio deixaria o morador sem notificação a vida
        // inteira, por causa de um par que ele nem preencheu de propósito.
        if ($start === $end) {
            return false;
        }

        return $start < $end
            ? $minutes >= $start && $minutes < $end
            : $minutes >= $start || $minutes < $end;
    }

    /**
     * Mescla as três fontes campo a campo, do mais específico ao mais geral.
     *
     * A linha resolvida é nova e não está no banco: ela é a resposta da
     * precedência, e gravar essa mistura sobrescreveria o que o morador
     * configurou.
     */
    private function merge(?NotificationPreference $own, ?NotificationPreference $house, NotificationPreference $environment): NotificationPreference
    {
        return new NotificationPreference([
            'quiet_start' => $own?->quiet_start ?? $house?->quiet_start ?? $environment->quiet_start,
            'quiet_end' => $own?->quiet_end ?? $house?->quiet_end ?? $environment->quiet_end,
            'digest' => $own?->digest ?? $house?->digest ?? $environment->digest,
            'preferred_hour' => $own?->preferred_hour ?? $house?->preferred_hour ?? $environment->preferred_hour,
        ]);
    }

    /** O padrão do ambiente, sem linha no banco. */
    private function fromEnvironment(): NotificationPreference
    {
        return new NotificationPreference([
            'quiet_start' => (string) config('notification.defaults.quiet_start'),
            'quiet_end' => (string) config('notification.defaults.quiet_end'),
            'digest' => (string) config('notification.defaults.digest'),
            'preferred_hour' => (string) config('notification.defaults.preferred_hour'),
        ]);
    }

    /**
     * O próximo horário preferido que ainda vai vir.
     *
     * O slot de hoje só serve se ele ainda estiver pela frente; depois disso é
     * o de amanhã, e um digest que saísse no slot que acabou de passar seria
     * um digest que nunca sai.
     */
    private function dailySlot(NotificationPreference $preference, CarbonImmutable $local): CarbonImmutable
    {
        $hour = $preference->preferred_hour?->format('H:i') ?? '00:00';
        [$h, $m] = array_pad(explode(':', $hour), 2, '00');

        $slot = $local->setTime((int) $h, (int) $m, 0);

        return $slot->greaterThan($local) ? $slot : $slot->addDay();
    }

    /**
     * O primeiro instante fora da janela.
     *
     * Numa janela que atravessa a meia-noite, quem chega depois do início
     * procura o fim do dia seguinte — é a hora que a noite acaba de verdade.
     */
    private function afterQuiet(NotificationPreference $preference, CarbonImmutable $local): CarbonImmutable
    {
        $end = $local->setTimeFromTimeString(
            $preference->quiet_end?->format('H:i') ?? '00:00',
        );

        return $end->greaterThan($local) ? $end : $end->addDay();
    }

    private function minutesOf(CarbonImmutable $local): int
    {
        return $local->hour * 60 + $local->minute;
    }
}
