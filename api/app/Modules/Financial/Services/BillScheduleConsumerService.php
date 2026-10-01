<?php

declare(strict_types=1);

namespace Mordomus\Financial\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Mordomus\Financial\Contracts\Repositories\BillOccurrenceRepositoryInterface;
use Mordomus\Financial\Contracts\Repositories\BillRepositoryInterface;
use Mordomus\Financial\Contracts\Services\BillScheduleConsumerServiceInterface;
use Mordomus\Financial\Contracts\Services\MoneyServiceInterface;
use Mordomus\Financial\Events\EventName;
use Mordomus\Financial\Models\Bill;
use Mordomus\Financial\Models\BillOccurrence;
use Mordomus\Scheduling\Contracts\Repositories\JobScheduleRepositoryInterface;
use Mordomus\Scheduling\Contracts\Services\EventPublisherServiceInterface;
use Mordomus\Scheduling\Models\TriggerConfig;

/**
 * Reação aos eventos do Scheduling: o vencimento que a casa vai pagar.
 *
 * A fila entrega o mesmo evento mais de uma vez (at-least-once), então tudo
 * aqui é escrito para a segunda entrega não virar segunda linha: a chave é
 * `unique(bill_id, due_date)` e a reconciliação só preenche o que falta.
 *
 * Nenhuma data é calculada neste módulo (regra R7): `scheduled_for` chega
 * pronto do motor e vai para a coluna como veio.
 */
final class BillScheduleConsumerService implements BillScheduleConsumerServiceInterface
{
    /** Valor previsto quando a conta ainda não tem valor conhecido. */
    private const AMOUNT_UNKNOWN = '0.00';

    public function __construct(
        private readonly BillRepositoryInterface $bills,
        private readonly BillOccurrenceRepositoryInterface $occurrences,
        private readonly JobScheduleRepositoryInterface $schedules,
        private readonly MoneyServiceInterface $money,
        private readonly EventPublisherServiceInterface $publisher,
    ) {}

    public function onOccurrenceCreated(string $tenantId, array $payload): void
    {
        $bill = $this->bills->find($this->string($payload, 'subject_id'), $tenantId);

        if ($bill === null) {
            // A regra aponta para uma conta que não existe na residência: é
            // dado inconsistente, e perder o vencimento em silêncio seria a
            // única forma de a conta sumir do histórico sem ninguém perceber.
            Log::warning('financial.bill_not_found_for_occurrence', [
                'tenant_id' => $tenantId,
                'bill_id' => $this->string($payload, 'subject_id'),
                'schedule_id' => $this->string($payload, 'schedule_id'),
            ]);

            return;
        }

        $this->materialize($tenantId, $bill, [
            'schedule_id' => $this->string($payload, 'schedule_id'),
            'due_date' => $this->string($payload, 'scheduled_for'),
        ]);
    }

    public function projectDueDates(string $tenantId): int
    {
        $created = 0;

        foreach ($this->schedules->forSubject($tenantId, TriggerConfig::SUBJECT_BILL) as $occurrence) {
            $bill = $this->bills->find((string) $occurrence->triggerConfig?->subjectId(), $tenantId);

            if ($bill === null) {
                continue;
            }

            $created += $this->materialize($tenantId, $bill, [
                'schedule_id' => (string) $occurrence->id,
                'due_date' => CarbonImmutable::parse($occurrence->scheduled_for)->format('Y-m-d'),
            ]) ? 1 : 0;
        }

        return $created;
    }

    /**
     * Vencimento a partir da data que o motor calculou.
     *
     * @param  array{schedule_id: string, due_date: string}  $payload
     */
    private function materialize(string $tenantId, Bill $bill, array $payload): bool
    {
        $existing = $this->occurrences->findByDueDate($bill->id, $payload['due_date']);

        if ($existing !== null) {
            $this->reconcile($existing, $payload['schedule_id'], $this->amount($bill));

            return false;
        }

        $this->occurrences->create([
            'tenant_id' => $tenantId,
            'bill_id' => $bill->id,
            'schedule_id' => $payload['schedule_id'],
            'due_date' => $payload['due_date'],
            'amount' => $this->amount($bill),
        ]);

        return true;
    }

    public function onDue(string $tenantId, array $payload): void
    {
        $occurrence = $this->occurrences->findByScheduleId($this->string($payload, 'schedule_id'));

        if ($occurrence === null) {
            Log::warning('financial.bill_occurrence_not_found_for_notice', [
                'tenant_id' => $tenantId,
                'schedule_id' => $this->string($payload, 'schedule_id'),
                'kind' => $this->string($payload, 'kind'),
            ]);

            return;
        }

        // Republicação no vocabulário da conta: quem notifica recebe
        // `bill_occurrence_id`, valor e dia de vencimento, e não precisa saber
        // que o alvo da regra era uma conta. O `dedupe_key` viaja junto para
        // que a deduplicação do Notification (R6) reconheça o aviso repetido
        // do mesmo vencimento.
        $this->publisher->publish(EventName::BILL_DUE, $tenantId, [
            'tenant_id' => $tenantId,
            'bill_occurrence_id' => $occurrence->id,
            'bill_id' => $occurrence->bill_id,
            'due_date' => $occurrence->dueDate(),
            'amount' => $occurrence->amount,
            'kind' => $this->string($payload, 'kind'),
            'due_at' => $this->string($payload, 'due_at'),
            'dedupe_key' => $this->string($payload, 'dedupe_key'),
        ]);
    }

    public function markOverdue(string $tenantId, string $today): int
    {
        $marked = $this->occurrences->markOverdue($tenantId, $today);

        if ($marked > 0) {
            Log::info('financial.bill_occurrences_overdue', [
                'tenant_id' => $tenantId,
                'bill_occurrences' => $marked,
                'before' => $today,
            ]);
        }

        return $marked;
    }

    /**
     * Segunda entrega do mesmo vencimento.
     *
     * Preenche o que falta e só mexe no valor enquanto a conta está em aberto:
     * depois de paga, o valor gravado é o que foi quitado e trocar de novo
     * reescreveria o histórico.
     */
    private function reconcile(BillOccurrence $occurrence, string $scheduleId, string $amount): void
    {
        $attributes = array_filter([
            'schedule_id' => $occurrence->isFromSchedule() ? null : $scheduleId,
            'amount' => $occurrence->isPayable() ? $amount : null,
        ], fn (mixed $value): bool => $value !== null);

        if ($attributes === []) {
            return;
        }

        $this->occurrences->change($occurrence, $attributes);
    }

    /** Conta variável não tem valor previsto: o lançamento começa em zero. */
    private function amount(Bill $bill): string
    {
        return $bill->amount === null
            ? self::AMOUNT_UNKNOWN
            : $this->money->fromCents($this->money->cents($bill->amount));
    }

    /** @param array<string, mixed> $payload */
    private function string(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) ? $value : '';
    }
}
