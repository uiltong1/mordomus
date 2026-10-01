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
}
