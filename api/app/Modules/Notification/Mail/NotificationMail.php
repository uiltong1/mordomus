<?php

declare(strict_types=1);

namespace Mordomus\Notification\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Queue\SerializesModels;
use Mordomus\Notification\Models\NotificationLog;

/**
 * E-mail do aviso.
 *
 * A view é uma casca só — assunto, linhas e o botão que leva à tela onde a
 * decisão acontece — porque o texto já nasceu no `NotificationMessageService`
 * e está gravado na linha. Duas versões do mesmo aviso é o jeito de a casa
 * receber "R$ 62,48" no push e "R$ 62,5" no e-mail.
 *
 * A linha da notificação viaja com o mailable em vez do id: assunto e corpo são
 * o que o e-mail mostra, e relê-los do banco dentro do worker seria uma
 * consulta que não compra nada.
 */
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

    /**
     * A tela interna do aviso, montada sobre a URL do ambiente.
     *
     * A rota vem do mesmo `url` que o push usa, e é ele que leva à agenda ou à
     * lista de contas: um e-mail sem botão de volta à tela é um e-mail que
     * obriga o morador a abrir o aplicativo e procurar.
     */
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
