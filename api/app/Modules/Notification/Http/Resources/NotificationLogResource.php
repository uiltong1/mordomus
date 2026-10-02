<?php

namespace Mordomus\Notification\Http\Resources;

use Illuminate\Support\Carbon;
use Mordomus\Notification\Models\NotificationLog;

/**
 * Shape da notificação, com o que foi entregue e por que não foi.
 *
 * `available_at` é o campo que o morador não deve ter que decifrar e que a
 * tela usa para dizer a verdade: um aviso que saiu às 07:00 porque às 23:00
 * ele dormia tem um instante previsto e outro realizado, e é a diferença entre
 * eles que explica a espera.
 */
final class NotificationLogResource
{
    /**
     * @return array<string, mixed>
     */
    public function make(NotificationLog $log, string $timezone): array
    {
        return [
            'id' => $log->id,
            'channel' => $log->channel,
            'template' => $log->template,
            'subject' => $log->subject,
            'body' => (array) $log->body,
            'status' => $log->status,
            'available_at' => $this->format($log->available_at, $timezone),
            'sent_at' => $this->format($log->sent_at, $timezone),
            'error' => $log->error,
            'created_at' => $this->format($log->created_at, $timezone),
        ];
    }

    /**
     * @param  iterable<NotificationLog>  $logs
     * @return list<array<string, mixed>>
     */
    public function collection(iterable $logs, string $timezone): array
    {
        return collect($logs)
            ->map(fn (NotificationLog $log): array => $this->make($log, $timezone))
            ->values()
            ->all();
    }

    private function format(mixed $value, string $timezone): ?string
    {
        return $value === null
            ? null
            : Carbon::parse($value)->timezone($timezone)->toIso8601String();
    }
}
