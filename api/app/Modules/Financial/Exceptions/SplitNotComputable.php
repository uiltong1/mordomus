<?php

declare(strict_types=1);

namespace Mordomus\Financial\Exceptions;

use Mordomus\Http\Exceptions\ApiException;

/**
 * A regra de divisão existe, mas não fecha a conta.
 *
 * A regra R5 exige que a soma das cotas seja o valor inteiro do lançamento. A
 * recusa vem do motor, e não da validação do payload: percentuais que somam 0,
 * pesos que somam 0 e cotas fechadas que passam do total só se sabe quando o
 * cálculo roda sobre o valor do vencimento — e é aí que o morador precisa da
 * resposta, com o número que não fecha no detalhe.
 */
final class SplitNotComputable extends ApiException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public static function make(string $reason, string $message, array $details = []): self
    {
        return new self(422, 'split_not_computable', $message, ['reason' => $reason] + $details);
    }

    /** @param array<string, mixed> $details */
    public static function noParticipants(array $details = []): self
    {
        return self::make(
            'no_participants',
            'A regra de divisão não tem moradores para dividir a conta.',
            $details,
        );
    }

    /** @param array<string, mixed> $details */
    public static function zeroTotal(string $field, array $details = []): self
    {
        return self::make(
            'zero_total',
            sprintf('A soma dos `%s` da regra é zero — não há proporção para dividir.', $field),
            ['field' => $field] + $details,
        );
    }

    /** @param array<string, mixed> $details */
    public static function exceedsTotal(array $details = []): self
    {
        return self::make(
            'exceeds_total',
            'A soma das cotas fechadas da regra passa do valor da conta.',
            $details,
        );
    }
}
