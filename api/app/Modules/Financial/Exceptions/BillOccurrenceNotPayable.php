<?php

declare(strict_types=1);

namespace Mordomus\Financial\Exceptions;

use Mordomus\Http\Exceptions\ApiException;

final class BillOccurrenceNotPayable extends ApiException
{
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
