<?php

declare(strict_types=1);

namespace Mordomus\Financial\Exceptions;

use Mordomus\Http\Exceptions\ApiException;

final class SplitNotComputable extends ApiException
{
    public static function make(string $reason, string $message, array $details = []): self
    {
        return new self(422, 'split_not_computable', $message, ['reason' => $reason] + $details);
    }

    public static function noParticipants(array $details = []): self
    {
        return self::make(
            'no_participants',
            'A regra de divisão não tem moradores para dividir a conta.',
            $details,
        );
    }

    public static function zeroTotal(string $field, array $details = []): self
    {
        return self::make(
            'zero_total',
            sprintf('A soma dos `%s` da regra é zero — não há proporção para dividir.', $field),
            ['field' => $field] + $details,
        );
    }

    public static function exceedsTotal(array $details = []): self
    {
        return self::make(
            'exceeds_total',
            'A soma das cotas fechadas da regra passa do valor da conta.',
            $details,
        );
    }
}
