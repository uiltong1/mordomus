<?php

declare(strict_types=1);

namespace Mordomus\Financial\Exceptions;

use Mordomus\Http\Exceptions\ApiException;

/**
 * O vencimento não tem regra de divisão, ou a casa não a tem ativo.
 *
 * Sem regra não há cota: o 404 é o honesto, porque a resposta que o morador
 * procura — "quanto é a minha parte" — não tem resposta enquanto a divisão não
 * existir. A regra da casa conta como resposta: ele cai no padrão quando a
 * conta não tem regra própria.
 */
final class SplitRuleNotFound extends ApiException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public static function make(array $details = []): self
    {
        return new self(
            404,
            'split_rule_not_found',
            'Esta conta não tem regra de divisão ativa na residência.',
            $details,
        );
    }
}
