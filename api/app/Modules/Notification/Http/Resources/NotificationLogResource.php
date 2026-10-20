<?php

namespace Mordomus\Notification\Http\Resources;

use Illuminate\Support\Carbon;
use Mordomus\Notification\Models\NotificationLog;

final class NotificationLogResource
{
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
