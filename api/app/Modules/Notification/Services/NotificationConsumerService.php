<?php

declare(strict_types=1);

namespace Mordomus\Notification\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Mordomus\Common\Support\TenantContext;
use Mordomus\Financial\Events\EventName as FinancialEventName;
use Mordomus\Identity\Contracts\Repositories\MembershipRepositoryInterface;
use Mordomus\Identity\Events\EventName as IdentityEventName;
use Mordomus\Notification\Contracts\Repositories\DeviceTokenRepositoryInterface;
use Mordomus\Notification\Contracts\Repositories\NotificationLogRepositoryInterface;
use Mordomus\Notification\Contracts\Repositories\NotificationPreferenceRepositoryInterface;
use Mordomus\Notification\Contracts\Services\NotificationConsumerServiceInterface;
use Mordomus\Notification\Contracts\Services\NotificationMessageServiceInterface;
use Mordomus\Notification\Contracts\Services\QuietWindowServiceInterface;
use Mordomus\Notification\Jobs\DeliverNotification;
use Mordomus\Notification\Models\NotificationLog;
use Mordomus\Scheduling\Contracts\Services\TenantCalendarServiceInterface;
use Mordomus\Scheduling\Events\EventName as ScheduleEventName;

/**
 * Envelope publicado → linha de notificação, com deduplicação e adiamento.
 *
 * O consumo é barato e idempotente de propósito: ele só resolve destinatário,
 * texto, canal e instante, grava a linha e despacha a entrega. A fila de
 * entrega é que carrega o retry e a DLQ, porque é no serviço de push e no SMTP
 * que a falha acontece — e uma falha de consumidor seria uma falha de escrita,
 * não de entrega.
 *
 * Quatro decisões que o código acima não explica sozinho:
 *
 * - **O destinatário é do evento.** `schedule.due` e `bill.due` vão para os
 *   moradores ativos da casa, porque a tarefa é da casa; `expense.split_computed`
 *   vai para quem está nas `shares`, porque a cota é de uma pessoa;
 *   `tenant.member_added` vai para quem entrou, porque é o único que não sabe
 *   que está dentro. Nenhum evento carrega autor, e a fila não é o lugar para
 *   pedir um: o contrato é o que é.
 *
 * - **O canal vem do `digest`.** `instant` é push — o canal do agora, e o que
 *   a casa assinou; `daily` é e-mail, que é o que espera o horário preferido.
 *   Um morador sem assinatura de push não recebe linha nenhuma em `instant`, e
 *   o motivo fica no log estruturado: inventar uma linha "sem destino" seria
 *   um aviso que nunca existiu.
 *
 * - **A deduplicação é o banco, não uma checagem antes.** A linha é inserida e
 *   a `dedupe_key` única recusa a segunda entrega. Checar antes deixaria a
 *   corrida entre duas entregas simultâneas passar, e é exatamente na
 *   reentrega que a fila é má.
 *
 * - **O adiamento mora na linha.** `available_at` é gravado com o instante já
 *   resolvido pelas quiet hours, e a entrega é despachada com atraso até lá. É
 *   o que faz o aviso de uma tarefa que venceu às 23:00 sair às 07:00 em vez
 *   de ser descartado — e `queued` na linha é a prova de que ele está
 *   esperando.
 */
final class NotificationConsumerService implements NotificationConsumerServiceInterface
{
    public function __construct(
        private readonly NotificationLogRepositoryInterface $logs,
        private readonly DeviceTokenRepositoryInterface $devices,
        private readonly NotificationPreferenceRepositoryInterface $preferences,
        private readonly MembershipRepositoryInterface $memberships,
        private readonly NotificationMessageServiceInterface $messages,
        private readonly QuietWindowServiceInterface $quiet,
        private readonly TenantCalendarServiceInterface $calendar,
    ) {}

    public function consume(array $envelope): int
    {
        $event = $this->string($envelope, 'event');
        $tenantId = $this->string($envelope, 'tenant_id');
        $payload = (array) ($envelope['payload'] ?? []);

        if ($event === '' || $tenantId === '') {
            Log::warning('notification.envelope_malformed', [
                'event' => $event !== '' ? $event : null,
                'envelope_keys' => array_keys($envelope),
            ]);

            return 0;
        }

        // O escopo global é fail-closed fora de contexto e o worker não tem
        // um: a moldura é a mesma que a varredura de cada residência usa.
        return TenantContext::runWith($tenantId, fn (): int => $this->deliverToRecipients($event, $tenantId, $payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function deliverToRecipients(string $event, string $tenantId, array $payload): int
    {
        $queued = 0;
        $duplicated = 0;
        $withoutChannel = 0;

        foreach ($this->recipients($event, $tenantId, $payload) as $recipientId) {
            $message = $this->messages->render($event, $payload, $tenantId, $recipientId);

            if ($message === null) {
                continue;
            }

            $preference = $this->quiet->effective($tenantId, $recipientId);
            $channel = $preference->isDaily()
                ? NotificationLog::CHANNEL_EMAIL
                : NotificationLog::CHANNEL_PUSH;

            if ($channel === NotificationLog::CHANNEL_PUSH && $this->devices->forUser($tenantId, $recipientId)->isEmpty()) {
                $withoutChannel++;

                continue;
            }

            $availableAt = $this->quiet->deliverAt(
                $preference,
                CarbonImmutable::now('UTC'),
                $this->calendar->timezone($tenantId),
            );

            $log = $this->queue($event, $tenantId, $payload, $recipientId, $channel, $availableAt, $message);

            if ($log === null) {
                $duplicated++;

                continue;
            }

            $this->dispatch($log, $availableAt);
            $queued++;
        }

        if ($queued > 0 || $duplicated > 0 || $withoutChannel > 0) {
            Log::info('notification.envelope_consumed', [
                'tenant_id' => $tenantId,
                'event' => $event,
                'queued' => $queued,
                'duplicated' => $duplicated,
                'without_channel' => $withoutChannel,
            ]);
        }

        return $queued;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function recipients(string $event, string $tenantId, array $payload): array
    {
        return match ($event) {
            // A tarefa é da casa: quem mora nela precisa saber.
            ScheduleEventName::SCHEDULE_DUE, FinancialEventName::BILL_DUE => $this->activeMembers($tenantId),
            // A cota é de quem está na divisão, e só dela.
            FinancialEventName::EXPENSE_SPLIT_COMPUTED => $this->shareHolders($payload),
            IdentityEventName::MEMBER_ADDED => $this->named([$this->string($payload, 'user_id')]),
            default => [],
        };
    }

    /** @return list<string> */
    private function activeMembers(string $tenantId): array
    {
        return $this->memberships->listActiveForTenant($tenantId)
            ->map(fn ($membership): string => (string) $membership->user_id)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function shareHolders(array $payload): array
    {
        $holders = [];

        foreach ((array) ($payload['shares'] ?? []) as $share) {
            if (is_array($share) && is_string($share['user_id'] ?? null) && $share['user_id'] !== '') {
                $holders[] = $share['user_id'];
            }
        }

        return $this->named($holders);
    }

    /**
     * @param  list<string>  $userIds
     * @return list<string>
     */
    private function named(array $userIds): array
    {
        return array_values(array_unique(array_filter($userIds, fn (string $id): bool => $id !== '')));
    }

    /**
     * Grava a intenção e devolve a linha, ou `null` quando a `dedupe_key` já
     * saiu — que é a resposta honesta para a reentrega da fila.
     *
     * O `available_at` vai para UTC porque é o fuso do morador que decide o
     * instante, e o que o banco guarda é o instante: gravado na hora local, o
     * Postgres lê como UTC e o adiamento da noite sai adiantado em três horas.
     *
     * @param  array<string, mixed>  $payload
     * @param  array{template: string, subject: string, body: array<string, mixed>}  $message
     */
    private function queue(
        string $event,
        string $tenantId,
        array $payload,
        string $recipientId,
        string $channel,
        CarbonImmutable $availableAt,
        array $message,
    ): ?NotificationLog {
        try {
            return $this->logs->create([
                'tenant_id' => $tenantId,
                'user_id' => $recipientId,
                'channel' => $channel,
                'template' => $message['template'],
                'subject' => mb_substr($message['subject'], 0, 200),
                'body' => $message['body'],
                'dedupe_key' => $this->dedupeKey($event, $payload, $channel, $recipientId),
                'status' => NotificationLog::STATUS_QUEUED,
                'available_at' => $availableAt->utc()->toIso8601String(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /**
     * A chave que segura o at-least-once.
     *
     * São quatro partes porque a notificação tem quatro identidades: o evento
     * (que aviso é), a chave do payload (qual ocorrência dele), o canal e o
     * morador. O mesmo evento para duas pessoas são duas notificações, e o
     * mesmo evento para a mesma pessoa no mesmo canal é uma.
     *
     * Quando o evento não traz `dedupe_key` — `tenant.member_added` não tem —
     * a impressão digital do payload entra no lugar, e é o mesmo raciocínio
     * com a fonte diferente.
     *
     * @param  array<string, mixed>  $payload
     */
    private function dedupeKey(string $event, array $payload, string $channel, string $recipientId): string
    {
        $subject = $this->string($payload, 'dedupe_key');

        if ($subject === '') {
            $subject = substr(hash('sha256', (string) json_encode($payload)), 0, 32);
        }

        return sprintf('%s:%s:%s:%s', $event, $subject, $channel, $recipientId);
    }

    private function dispatch(NotificationLog $log, CarbonImmutable $availableAt): void
    {
        // O Carbon 3 mede a diferença de `agora` para o argumento, e não o
        // contrário: `$a->diffInSeconds($b)` é `$b - $a`. Invertido, o atraso do
        // adiamento sai negativo e vira zero — que é mandar para a fila um aviso
        // que a quiet hours acabou de adiar.
        $delay = CarbonImmutable::now('UTC')->diffInSeconds($availableAt);
        $queue = $log->isPush()
            ? (string) config('notification.queues.push')
            : (string) config('notification.queues.emails');

        DeliverNotification::dispatch($log)
            ->onQueue($queue)
            ->delay(max(0, (int) $delay));
    }

    /** @param array<string, mixed> $source */
    private function string(array $source, string $key): string
    {
        $value = $source[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }
}
