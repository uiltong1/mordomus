<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Exceptions;

use Mordomus\Http\Exceptions\ApiException;

/**
 * A ocorrência já saiu do ciclo: concluída ou dispensada.
 *
 * Concluir duas vezes é inofensivo e devolve a mesma resposta (R3); dispensa
 * seguida de check-in é contradição, e aí o conflito é o honesto.
 */
final class OccurrenceAlreadyFinalized extends ApiException
{
    public static function make(string $status, string $operation): self
    {
        return new self(
            409,
            'occurrence_already_finalized',
            'A ocorrência já foi encerrada e não aceita esta operação.',
            ['status' => $status, 'operation' => $operation],
        );
    }
}
