<?php

declare(strict_types=1);

namespace Mordomus\Http\Exceptions;

use RuntimeException;

/**
 * Falha de domínio já traduzida para o contrato HTTP: o service lança e o
 * `render()` de bootstrap devolve o mesmo `ErrorEnvelope` que o caminho
 * direto do controller produzia.
 */
abstract class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    final protected function __construct(
        private readonly int $status,
        private readonly string $errorCode,
        string $message,
        private readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return $this->details;
    }
}
