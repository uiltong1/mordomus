<?php

declare(strict_types=1);

namespace Mordomus\Identity\Contracts\Services;

interface JwtVerifierServiceInterface
{
    /**
     * Claims decodificados; lança as exceções do JWT que o guard converte
     * em "não autenticado".
     *
     * @return array<string, mixed>
     */
    public function verify(string $token): array;
}
