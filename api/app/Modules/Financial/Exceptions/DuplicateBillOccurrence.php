<?php

declare(strict_types=1);

namespace Mordomus\Financial\Exceptions;

use Mordomus\Http\Exceptions\ApiException;

/**
 * Já existe vencimento da mesma conta na mesma data.
 *
 * A chave é `unique(bill_id, due_date)` no banco, e ela existe para o
 * consumidor do Scheduling: o mesmo evento reentregue pela fila tentaria a
 * mesma linha. No lançamento manual o conflito vira erro explícito, porque quem
 * digitou duas vezes a mesma conta precisa saber que errou.
 */
final class DuplicateBillOccurrence extends ApiException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public static function make(string $billId, string $dueDate, array $details = []): self
    {
        return new self(
            409,
            'bill_occurrence_exists',
            'Já existe um vencimento desta conta nesta data.',
            ['bill_id' => $billId, 'due_date' => $dueDate] + $details,
        );
    }
}
