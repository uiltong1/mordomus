<?php

declare(strict_types=1);

namespace Mordomus\Identity\Contracts\Services;

interface JwtVerifierServiceInterface
{
    public function verify(string $token): array;
}
