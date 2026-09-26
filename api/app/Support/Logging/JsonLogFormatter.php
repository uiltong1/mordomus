<?php

declare(strict_types=1);

namespace Mordomus\Support\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;

/**
 * Cada linha do log é um objeto JSON de nível raiz — consumível por
 * coletor/linter sem parsear o formato de texto do LineFormatter.
 */
final class JsonLogFormatter extends JsonFormatter
{
    public function format(LogRecord $record): string
    {
        $payload = [
            'timestamp' => $record->datetime->format(DATE_ATOM),
            'level' => $record->level->name,
            'channel' => $record->channel,
            'message' => $record->message,
            'context' => $this->normalizeContext($record->context),
        ];

        return (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function normalizeContext(array $context): array
    {
        if (! isset($context['exception']) || ! $context['exception'] instanceof \Throwable) {
            return $context;
        }

        $exception = $context['exception'];
        $context['exception'] = [
            'class' => $exception::class,
            'message' => $exception->getMessage(),
            'file' => $exception->getFile().':'.$exception->getLine(),
        ];

        return $context;
    }
}
