<?php

declare(strict_types=1);

return [

    'queues' => [
        'events' => env('NOTIFICATION_EVENTS_QUEUE', 'mordomus:notification:events'),
        'push' => env('NOTIFICATION_PUSH_QUEUE', 'mordomus:notification:push'),
        'emails' => env('NOTIFICATION_EMAILS_QUEUE', 'mordomus:notification:emails'),
    ],

    'vapid' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),

        'subject' => env('VAPID_SUBJECT', 'mailto:suporte@mordomus.app'),
    ],

    'defaults' => [
        'quiet_start' => env('NOTIFICATION_QUIET_START', '22:00'),
        'quiet_end' => env('NOTIFICATION_QUIET_END', '07:00'),
        'digest' => env('NOTIFICATION_DIGEST', 'instant'),
        'preferred_hour' => env('NOTIFICATION_PREFERRED_HOUR', '09:00'),
    ],

    'delivery' => [
        'ttl' => (int) env('NOTIFICATION_TTL_SECONDS', 86400),
        'timeout' => (int) env('NOTIFICATION_TIMEOUT_SECONDS', 10),
        'tries' => (int) env('NOTIFICATION_TRIES', 3),
        'backoff' => [10, 60, 300],
    ],

];
