<?php

namespace Mordomus\Financial\Events;

/**
 * Nomes dos eventos que o Financial publica.
 *
 * Ficam numa classe só porque o nome é o contrato: é o que o Notification
 * conhece e o que o JSON Schema de `packages/contracts` declara.
 */
final class EventName
{
    /** Vencimento de conta que a casa precisa lembrar de pagar. */
    public const BILL_DUE = 'bill.due';

    /** Quitação registrada, com quem pagou e por quanto. */
    public const BILL_PAID = 'bill.paid';

    /**
     * Cotas do vencimento calculadas, uma vez por mudança no número.
     *
     * O `dedupe_key` carrega o resumo das cotas, então a reentrega da fila e a
     * regra salva sem diferença real não viram dois avisos do mesmo lançamento.
     */
    public const EXPENSE_SPLIT_COMPUTED = 'expense.split_computed';
}
