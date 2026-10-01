<?php

declare(strict_types=1);

namespace Mordomus\Financial\Exceptions;

use Mordomus\Http\Exceptions\ApiException;

/**
 * O morador não tem cota neste vencimento.
 *
 * Baixar uma cota que não existe é confusão entre a conta e o morador, e a
 * resposta precisa dizer qual dos dois não casou.
 */
final class SplitResultNotFound extends ApiException
{
    /**
     * @param  array<string, mixed>  $details
     */
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
