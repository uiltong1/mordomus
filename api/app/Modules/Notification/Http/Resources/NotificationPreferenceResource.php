<?php

namespace Mordomus\Notification\Http\Resources;

use Mordomus\Notification\Models\NotificationPreference;

/**
 * As três camadas da preferência: a do morador, a da casa e a que vale.
 *
 * A tela precisa das três porque elas respondem a perguntas diferentes — o que
 * eu digitei, o que a casa definiu e o que vai acontecer agora. Mandar só a
 * resolvida esconderia de onde ela veio, e o morador que quer tirar o que a
 * casa impõe precisa saber que existe algo para tirar.
 */
final class NotificationPreferenceResource
{
    /**
     * @return array<string, mixed>
     */
    public function make(
        ?NotificationPreference $own,
        ?NotificationPreference $house,
        NotificationPreference $effective,
    ): array {
        return [
            'own' => $own === null ? null : $this->layer($own),
            'house' => $house === null ? null : $this->layer($house),
            'effective' => $this->layer($effective),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function layer(NotificationPreference $preference): array
    {
        return [
            'quiet_start' => $preference->quiet_start?->format('H:i'),
            'quiet_end' => $preference->quiet_end?->format('H:i'),
            'quiet_window_wraps_midnight' => $preference->quietWindowWrapsMidnight(),
            'digest' => $preference->digest,
            'preferred_hour' => $preference->preferred_hour?->format('H:i'),
        ];
    }
}
