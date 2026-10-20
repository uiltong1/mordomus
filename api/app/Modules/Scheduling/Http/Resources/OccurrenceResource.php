<?php

namespace Mordomus\Scheduling\Http\Resources;

use Illuminate\Support\Carbon;
use Mordomus\Scheduling\Models\JobSchedule;

final class OccurrenceResource
{
    public function make(JobSchedule $occurrence, string $timezone): array
    {
        return [
            'id' => $occurrence->id,
            'tenant_id' => $occurrence->tenant_id,
            'trigger_config_id' => $occurrence->trigger_config_id,

            'subject_type' => $occurrence->triggerConfig->subject_type,
            'subject_id' => $occurrence->triggerConfig->subjectId(),
            'title' => $occurrence->triggerConfig->title,
            'scheduled_for' => $occurrence->scheduled_for?->format('Y-m-d'),
            'due_at' => $this->format($occurrence->due_at, $timezone),
            'status' => $occurrence->status,
            'notified_at' => $this->format($occurrence->notified_at, $timezone),
            'completed_at' => $this->format($occurrence->completed_at, $timezone),
            'completed_by' => $occurrence->completed_by,
            'created_at' => $this->format($occurrence->created_at, $timezone),
            'updated_at' => $this->format($occurrence->updated_at, $timezone),
        ];
    }

    public function collection(iterable $occurrences, string $timezone): array
    {
        return collect($occurrences)
            ->map(fn (JobSchedule $occurrence): array => $this->make($occurrence, $timezone))
            ->values()
            ->all();
    }

    private function format(mixed $value, string $timezone): ?string
    {
        if ($value === null) {
            return null;
        }

        return Carbon::parse($value)->timezone($timezone)->toIso8601String();
    }
}
