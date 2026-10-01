<?php

declare(strict_types=1);

namespace Mordomus\Financial\Exceptions;

use Mordomus\Http\Exceptions\ApiException;

/**
 * O vencimento não está em estado de receber pagamento.
 *
 * Pagar duas vezes é inofensivo e devolve o mesmo lançamento; pagar um
 * vencimento cancelado é contradição, e aí o conflito é o honesto.
 */
final class BillOccurrenceNotPayable extends ApiException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public static function make(string $status, array $details = []): self
    {
        return new self(
            409,
            'bill_occurrence_not_payable',
            'O vencimento não está em aberto para receber pagamento.',
            ['status' => $status] + $details,
        );
    }
}
