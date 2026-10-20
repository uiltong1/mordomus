<?php

namespace Mordomus\Notification\Http\Resources;

use Mordomus\Notification\Models\NotificationPreference;

final class NotificationPreferenceResource
{
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
