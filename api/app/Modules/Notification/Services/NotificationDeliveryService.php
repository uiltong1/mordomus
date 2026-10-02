<?php

declare(strict_types=1);

namespace Mordomus\Notification\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Mordomus\Identity\Models\User;
use Mordomus\Notification\Contracts\Repositories\DeviceTokenRepositoryInterface;
use Mordomus\Notification\Contracts\Repositories\NotificationLogRepositoryInterface;
use Mordomus\Notification\Contracts\Services\NotificationDeliveryServiceInterface;
use Mordomus\Notification\Contracts\Services\PushChannelInterface;
use Mordomus\Notification\Jobs\DeliverNotification;
use Mordomus\Notification\Mail\NotificationMail;
use Mordomus\Notification\Models\NotificationLog;

/**
 * Entrega uma notificação pelo canal que a linha diz.
 *
 * O retry e a DLQ são do job, não daqui: a exceção sobe e o job decide. O que
 * este serviço faz é manter a linha dizendo a verdade — `queued` enquanto
 * ninguém respondeu, `sent` quando respondeu, `failed` quando desistiu.
 *
 * Três decisões que o código acima não explica sozinho:
 *
 * - **A janela de silêncio é conferida de novo aqui.** O atraso do despacho é o
 *   mecanismo, e ele é suficiente; a conferida é para o caso de o job ser
 *   executado cedo demais (relojão, worker reaproveitado). Aí a linha volta
 *   para a fila com atraso, num job novo — assim o adiamento não gasta uma das
 *   tentativas, que são para o serviço de push estar fora do ar.
 *
 * - **O fan-out por assinatura repete o aviso.** Se uma das assinaturas do
 *   morador falhar, a exceção sobe e o job repete para as outras. O que evita
 *   o card duplicado na tela do morador é o `tag`: o service worker substitui a
 *   notificação que tem a mesma tag em vez de empilhar outra.
 *
 * - **Assinatura morta é `false`, não exceção.** O serviço de push responde
 *   404/410 quando a assinatura não existe mais, e tentar de novo é tentar para
 *   sempre: a linha sai e o resto do fan-out continua.
 */
final class NotificationDeliveryService implements NotificationDeliveryServiceInterface
{
    public function __construct(
        private readonly NotificationLogRepositoryInterface $logs,
        private readonly DeviceTokenRepositoryInterface $devices,
        private readonly PushChannelInterface $push,
    ) {}

    public function deliver(NotificationLog $log): void
    {
        if (! $log->isQueued()) {
            return;
        }

        $wait = $this->secondsUntil($log);

        if ($wait > 0) {
            $this->requeue($log, $wait);

            return;
        }

        match ($log->channel) {
            NotificationLog::CHANNEL_PUSH => $this->push($log),
            NotificationLog::CHANNEL_EMAIL => $this->email($log),
            default => $this->fail($log, sprintf('Canal desconhecido: %s', $log->channel)),
        };
    }

    private function push(NotificationLog $log): void
    {
        $devices = $this->devices->forUser((string) $log->tenant_id, (string) $log->user_id);

        foreach ($devices as $device) {
            if (! $this->push->send($device, (array) $log->body)) {
                $this->devices->forget(
                    (string) $log->tenant_id,
                    (string) $log->user_id,
                    (string) $device->endpoint,
                );
            }
        }

        $this->logs->change($log, [
            'status' => NotificationLog::STATUS_SENT,
            'sent_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'error' => null,
        ]);
    }

    private function email(NotificationLog $log): void
    {
        $user = User::query()->find($log->user_id);

        if ($user === null) {
            $this->fail($log, 'O morador do aviso não existe mais.');

            return;
        }

        Mail::to($user->email)->send(new NotificationMail($log));

        $this->logs->change($log, [
            'status' => NotificationLog::STATUS_SENT,
            'sent_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'error' => null,
        ]);
    }

    /** A linha volta para a fila adiada, num job novo. */
    private function requeue(NotificationLog $log, int $seconds): void
    {
        Log::info('notification.delivery_deferred', [
            'tenant_id' => $log->tenant_id,
            'user_id' => $log->user_id,
            'notification_log_id' => $log->id,
            'deferred_seconds' => $seconds,
        ]);

        DeliverNotification::dispatch($log)
            ->onQueue($this->queueOf($log))
            ->delay($seconds);
    }

    public function fail(NotificationLog $log, string $reason): void
    {
        Log::error('notification.delivery_failed', [
            'tenant_id' => $log->tenant_id,
            'user_id' => $log->user_id,
            'notification_log_id' => $log->id,
            'channel' => $log->channel,
            'template' => $log->template,
            'reason' => $reason,
        ]);

        $this->logs->change($log, [
            'status' => NotificationLog::STATUS_FAILED,
            'error' => mb_substr($reason, 0, 2000),
        ]);
    }

    /**
     * Quanto falta para a linha poder sair.
     *
     * O Carbon 3 mede de `agora` para o argumento e devolve `float`, e os dois
     * detalhes importam: invertido, um adiamento vira zero; em float, a linha
     * adiada estoura o `int` do retorno e a entrega quebra na hora de reentrar
     * na fila.
     */
    private function secondsUntil(NotificationLog $log): int
    {
        if ($log->available_at === null) {
            return 0;
        }

        return max(0, (int) CarbonImmutable::now('UTC')->diffInSeconds($log->available_at));
    }

    private function queueOf(NotificationLog $log): string
    {
        return $log->isPush()
            ? (string) config('notification.queues.push')
            : (string) config('notification.queues.emails');
    }
}
