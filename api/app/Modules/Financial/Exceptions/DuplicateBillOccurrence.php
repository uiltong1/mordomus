<?php

declare(strict_types=1);

namespace Mordomus\Financial\Exceptions;

use Mordomus\Http\Exceptions\ApiException;

final class DuplicateBillOccurrence extends ApiException
{
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
