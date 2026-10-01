<?php

declare(strict_types=1);

namespace Mordomus\Financial\Contracts\Services;

/**
 * Reação aos eventos do Scheduling dentro do processo (ADR-011).
 *
 * É o caminho inverso ao do módulo dono: o Scheduling publica, o Financial
 * persiste. Nenhuma das duas pontas calcula data — a do evento já vem pronta,
 * e a outra só a grava.
 */
interface BillScheduleConsumerServiceInterface
{
    /**
     * Vencimento que a casa vai pagar: grava (ou reconcilia) a linha a partir
     * da data que o motor materializou.
     */
    public function onOccurrenceCreated(string $tenantId, array $payload): void;

    /**
     * Reconcilia os vencimentos a partir da agenda do Scheduling.
     *
     * O evento é o caminho rápido, mas ele é publicado na mesma transação que
     * grava a ocorrência: um consumidor que falhou depois disso não recebe
     * reentrega (o motor já considera a data materializada) e a linha ficaria
     * faltando para sempre. Esta passagem refaz o que o evento faria, a partir
     * da mesma fonte, e é idempotente.
     *
     * @return int vencimentos criados nesta passagem
     */
    public function projectDueDates(string $tenantId): int;

    /**
     * Aviso de vencimento: republica no vocabulário da conta, para quem
     * notifica não precisar saber que alvo era `bill`.
     */
    public function onDue(string $tenantId, array $payload): void;

    /**
     * Projeção de atraso: o que venceu e continua aberto vira `overdue`.
     *
     * @return int quantidade de vencimentos que entraram na condição
     */
    public function markOverdue(string $tenantId, string $today): int;
}
