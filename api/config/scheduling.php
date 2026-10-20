<?php

declare(strict_types=1);

return [

    'horizon_days' => (int) env('SCHEDULING_HORIZON_DAYS', 45),

    'queues' => [
        'occurrences' => env('SCHEDULING_QUEUE', 'mordomus:scheduling:occurrences'),
        'events' => env('SCHEDULING_EVENTS_QUEUE', 'mordomus:notification:events'),
    ],

];
