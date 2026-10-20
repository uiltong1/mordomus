<?php

declare(strict_types=1);

namespace Mordomus\Http\Exceptions;

use RuntimeException;

abstract class ApiException extends RuntimeException
{
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

    public function details(): array
    {
        return $this->details;
    }
}
