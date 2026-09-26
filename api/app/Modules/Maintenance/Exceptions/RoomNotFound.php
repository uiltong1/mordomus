<?php

declare(strict_types=1);

namespace Mordomus\Maintenance\Exceptions;

use Mordomus\Http\Exceptions\ApiException;

final class RoomNotFound extends ApiException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public static function make(array $details = []): self
    {
        return new self(
            404,
            'room_not_found',
            'Cômodo não encontrado ou arquivado na residência ativa.',
            $details,
        );
    }
}
