<?php

declare(strict_types=1);

namespace Mordomus\Financial\Exceptions;

use Mordomus\Http\Exceptions\ApiException;

final class SplitResultNotFound extends ApiException
{
    public static function make(string $billOccurrenceId, string $userId): self
    {
        return new self(
            404,
            'split_result_not_found',
            'Este morador não tem cota-parte neste vencimento.',
            [
                'bill_occurrence_id' => $billOccurrenceId,
                'user_id' => $userId,
            ],
        );
    }
}
