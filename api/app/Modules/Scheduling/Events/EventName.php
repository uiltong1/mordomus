<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Events;

final class EventName
{
    public const SCHEDULE_DUE = 'schedule.due';

    public const OCCURRENCE_COMPLETED = 'occurrence.completed';

    public const OCCURRENCE_CREATED = 'schedule.occurrence.created';
}
