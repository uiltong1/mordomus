<?php

declare(strict_types=1);

namespace Mordomus\Notification\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Queue\SerializesModels;
use Mordomus\Notification\Models\NotificationLog;

class NotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly NotificationLog $log) {}

    public function content(): Content
    {
        return new Content(
            view: 'mordomus::notification',
            with: [
                'subject' => $this->log->subject,
                'lines' => (array) ($this->log->body['lines'] ?? []),
                'actionUrl' => $this->actionUrl(),
            ],
        );
    }

    private function actionUrl(): ?string
    {
        $path = $this->log->body['url'] ?? null;
        $base = rtrim((string) config('app.url'), '/');

        if (! is_string($path) || $path === '' || $base === '') {
            return null;
        }

        return $base.'/'.ltrim($path, '/');
    }
}
