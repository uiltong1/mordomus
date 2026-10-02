<?php

namespace Mordomus\Notification\Exceptions;

use Mordomus\Http\Exceptions\ApiException;

/**
 * A assinatura não existe mais para este morador.
 *
 * O `DELETE` é idempotente na prática — desinscrever duas vezes não tem o que
 * apagar — e o 404 aqui é o mesmo que viria de uma linha que nunca existiu.
 */
final class DeviceTokenNotFound extends ApiException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public static function make(array $details = []): self
    {
        return new self(
            404,
            'device_token_not_found',
            'Esta assinatura de push não está registrada para você.',
            $details,
        );
    }
}
