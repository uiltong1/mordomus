<?php

declare(strict_types=1);

namespace Mordomus\Http\Exceptions;

final class ResourceNotFound extends ApiException
{
    public static function make(): self
    {
        return new self(404, 'not_found', 'Recurso não encontrado.');
    }
}
