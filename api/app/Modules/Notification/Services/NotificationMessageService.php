<?php

declare(strict_types=1);

namespace Mordomus\Notification\Services;

use Carbon\CarbonImmutable;
use Mordomus\Financial\Events\EventName as FinancialEventName;
use Mordomus\Identity\Events\EventName as IdentityEventName;
use Mordomus\Notification\Contracts\Services\NotificationMessageServiceInterface;
use Mordomus\Scheduling\Contracts\Services\TenantCalendarServiceInterface;
use Mordomus\Scheduling\Events\EventName as ScheduleEventName;
use Throwable;

/**
 * O texto de cada evento, na língua do morador e no fuso da casa.
 *
 * O que entra no aviso é o que o morador precisa para agir, e nada mais: o
 * título da tarefa, o dia e a hora em que ela vence. O resto do payload é
 * endereçado ao serviço, não à pessoa.
 *
 * `url` aponta para a tela onde a decisão acontece — é o que o service worker
 * abre no toque, e um aviso que não leva a lugar nenhum é um aviso que o
 * morador aprende a ignorar.
 *
 * `tag` é a chave que substitui a notificação anterior em vez de acumular: o
 * mesmo aviso da mesma tarefa é um card só, atualizado quando muda.
 *
 * O corpo sai em `lines` e não em texto corrido porque o e-mail as mostra uma
 * por linha e o push as junta: as duas leituras do mesmo aviso saem da mesma
 * lista, e é isso que impede "R$ 62,48" no push e "R$ 62,5" no e-mail.
 */
final class NotificationMessageService implements NotificationMessageServiceInterface
{
    public function __construct(
        private readonly TenantCalendarServiceInterface $calendar,
    ) {}

    public function render(string $event, array $payload, string $tenantId, ?string $recipientId): ?array
    {
        return match ($event) {
            ScheduleEventName::SCHEDULE_DUE => $this->scheduleDue($payload, $tenantId),
            FinancialEventName::BILL_DUE => $this->billDue($payload),
            FinancialEventName::EXPENSE_SPLIT_COMPUTED => $this->splitComputed($payload, $recipientId),
            IdentityEventName::MEMBER_ADDED => $this->memberAdded($payload),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{template: string, subject: string, body: array<string, mixed>}
     */
    private function scheduleDue(array $payload, string $tenantId): array
    {
        $title = $this->string($payload, 'title') ?: 'Tarefa da casa';
        $when = $this->when($payload['due_at'] ?? null, $tenantId);

        return [
            'template' => 'mordomus::'.ScheduleEventName::SCHEDULE_DUE,
            'subject' => sprintf('%s — %s', $title, $when),
            'body' => [
                'title' => $title,
                'lines' => [sprintf('%s está marcada para %s.', $title, $when)],
                'tag' => $this->tag($payload),
                'url' => '/agenda',
                'data' => ['schedule_id' => $this->string($payload, 'schedule_id')],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{template: string, subject: string, body: array<string, mixed>}
     */
    private function billDue(array $payload): array
    {
        $dueDate = $this->string($payload, 'due_date');
        $amount = $this->string($payload, 'amount');
        $label = $this->string($payload, 'title') ?: 'Conta da casa';

        return [
            'template' => 'mordomus::'.FinancialEventName::BILL_DUE,
            'subject' => sprintf('%s vence em %s', $label, $this->shortDate($dueDate)),
            'body' => [
                'title' => sprintf('%s: R$ %s', $label, $amount),
                'lines' => [
                    sprintf('A conta vence em %s.', $this->shortDate($dueDate)),
                    sprintf('Valor de R$ %s.', $amount),
                ],
                'tag' => $this->tag($payload),
                'url' => '/contas',
                'data' => ['bill_occurrence_id' => $this->string($payload, 'bill_occurrence_id')],
            ],
        ];
    }

    /**
     * A cota vai no plural de quem recebeu, e não no da conta.
     *
     * O aviso é sobre a parte de uma pessoa: a conta inteira já está no card
     * de vencimentos, e o que o morador precisa saber aqui é quanto saiu do
     * bolso dele.
     *
     * @param  array<string, mixed>  $payload
     * @return array{template: string, subject: string, body: array<string, mixed>}
     */
    private function splitComputed(array $payload, ?string $recipientId): array
    {
        $share = $this->shareOf($payload, $recipientId);
        $label = $this->string($payload, 'title') ?: 'Conta da casa';

        return [
            'template' => 'mordomus::'.FinancialEventName::EXPENSE_SPLIT_COMPUTED,
            'subject' => sprintf('Sua parte de %s: R$ %s', $label, $share),
            'body' => [
                'title' => sprintf('Sua parte: R$ %s', $share),
                'lines' => [
                    sprintf('A divisão de %s saiu.', $label),
                    sprintf('Conta de R$ %s — a sua parte é R$ %s.', $this->string($payload, 'amount'), $share),
                ],
                'tag' => $this->tag($payload),
                'url' => '/contas',
                'data' => ['bill_occurrence_id' => $this->string($payload, 'bill_occurrence_id')],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{template: string, subject: string, body: array<string, mixed>}
     */
    private function memberAdded(array $payload): array
    {
        $home = $this->string($payload, 'home_name') ?: 'sua nova residência';

        return [
            'template' => 'mordomus::'.IdentityEventName::MEMBER_ADDED,
            'subject' => sprintf('Você entrou em %s', $home),
            'body' => [
                'title' => sprintf('Bem-vindo a %s', $home),
                'lines' => [sprintf('Você entrou em %s e já pode ver a casa.', $home)],
                'tag' => $this->tag($payload),
                'url' => '/',
                'data' => ['user_id' => $this->string($payload, 'user_id')],
            ],
        ];
    }

    /**
     * A cota de um morador no payload de split.
     *
     * @param  array<string, mixed>  $payload
     */
    private function shareOf(array $payload, ?string $recipientId): string
    {
        foreach ((array) ($payload['shares'] ?? []) as $share) {
            if (is_array($share) && ($share['user_id'] ?? null) === $recipientId) {
                return $this->string($share, 'share_amount');
            }
        }

        return '0.00';
    }

    /**
     * Chave que substitui o card anterior em vez de acumular outro.
     *
     * @param  array<string, mixed>  $payload
     */
    private function tag(array $payload): string
    {
        $subject = $this->string($payload, 'bill_occurrence_id')
            ?: $this->string($payload, 'schedule_id')
            ?: $this->string($payload, 'user_id');

        return 'mordomus:'.($subject !== '' ? $subject : 'geral');
    }

    /** @param array<string, mixed> $payload */
    private function string(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    private function when(mixed $instant, string $tenantId): string
    {
        if (! is_string($instant) || $instant === '') {
            return 'em breve';
        }

        try {
            return CarbonImmutable::parse($instant)
                ->setTimezone($this->calendar->timezone($tenantId))
                ->translatedFormat('d/m \às H:i');
        } catch (Throwable) {
            return 'em breve';
        }
    }

    /** Dia de calendário, sem fuso: `2026-04-10` é o dia que o morador lê. */
    private function shortDate(string $day): string
    {
        if ($day === '') {
            return 'em breve';
        }

        try {
            return CarbonImmutable::parse($day)->format('d/m/Y');
        } catch (Throwable) {
            return $day;
        }
    }
}
