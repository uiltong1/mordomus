<?php

namespace Mordomus\Scheduling\Http\Resources;

use Illuminate\Support\Carbon;
use Mordomus\Scheduling\Models\JobSchedule;

/**
 * Shape da ocorrência, com o alvo no par `subject_type`/`subject_id` e as
 * datas no fuso da residência.
 *
 * `title` vem da regra: a agenda precisa dizer o que é cada linha, e o
 * cliente não deveria precisar de uma segunda chamada para isso.
 */
final class OccurrenceResource
{
    /**
     * @return array<string, mixed>
     */
    public function make(JobSchedule $occurrence, string $timezone): array
    {
        return [
            'id' => $occurrence->id,
            'tenant_id' => $occurrence->tenant_id,
            'trigger_config_id' => $occurrence->trigger_config_id,
            // Sem `?->`: a FK leva a ocorrência junto quando a regra é
            // excluída, então a relação está sempre presente na leitura — e um
            // alvo nulo aqui seria um bug silencioso para o cliente.
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

    /**
     * @param  iterable<JobSchedule>  $occurrences
     * @return list<array<string, mixed>>
     */
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
