<?php

declare(strict_types=1);

namespace Mordomus\Support\Contracts;

use Opis\JsonSchema\Exceptions\ParseException;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;

final class ContractSchemaValidator
{
    private const DIALECT = 'https://json-schema.org/draft/2020-12/schema';

    private const ID_PREFIX = 'https://mordomus.app/contracts/';

    private const ENVELOPE_REQUIRED = ['event', 'event_id', 'occurred_at', 'tenant_id', 'payload'];

    public function __construct(private readonly string $contractsPath) {}

    public function inspect(array $publishedEvents): array
    {
        $findings = [];
        $declared = [];

        foreach ($this->schemaFiles() as $file) {
            $name = $this->eventNameOf($file);
            $declared[$name] = $file;

            foreach ($this->inspectSchema($file, $name) as $finding) {
                $findings[] = $finding;
            }
        }

        foreach ($publishedEvents as $event => $publishers) {
            if (! isset($declared[$event])) {
                $findings[] = sprintf(
                    'evento `%s` (publicado por %s) não tem schema em %s',
                    $event,
                    implode(', ', $publishers),
                    $this->contractsPath,
                );
            }
        }

        foreach (array_keys($declared) as $event) {
            if (! array_key_exists($event, $publishedEvents)) {
                $findings[] = sprintf(
                    'schema `%s.schema.json` não corresponde a nenhum evento publicado — evento removido ou renomeado sem apagar o contrato',
                    $event,
                );
            }
        }

        return $findings;
    }

    private function inspectSchema(string $file, string $event): array
    {
        $where = basename($file);
        $raw = (string) file_get_contents($file);
        $schema = json_decode($raw, true);

        if (! is_array($schema)) {
            return [$where.': JSON inválido — '.json_last_error_msg()];
        }

        $findings = [];

        if (($schema['$schema'] ?? null) !== self::DIALECT) {
            $findings[] = sprintf('%s: `$schema` deve ser `%s`', $where, self::DIALECT);
        }

        $expectedId = self::ID_PREFIX.$event.'.schema.json';

        if (($schema['$id'] ?? null) !== $expectedId) {
            $findings[] = sprintf('%s: `$id` deve ser `%s`', $where, $expectedId);
        }

        if (($schema['title'] ?? null) !== $event) {
            $findings[] = sprintf('%s: `title` deve ser o nome do evento (`%s`)', $where, $event);
        }

        if (($schema['properties']['event']['const'] ?? null) !== $event) {
            $findings[] = sprintf('%s: `properties.event.const` deve ser `%s`', $where, $event);
        }

        $findings = array_merge($findings, $this->inspectEnvelope($where, $schema), $this->inspectPayload($where, $schema));

        return array_merge($findings, $this->inspectPatterns($where, $schema), $this->inspectParsable($where, $raw));
    }

    private function inspectPatterns(string $where, array $node, string $path = ''): array
    {
        $findings = [];

        foreach ($node as $keyword => $value) {
            $current = $path === '' ? (string) $keyword : $path.'.'.$keyword;

            if ($keyword === 'pattern') {
                if (! is_string($value) || @preg_match(Helper::patternToRegex($value), '') === false) {
                    $findings[] = sprintf('%s: `%s` não é uma expressão regular válida', $where, $current);
                }

                continue;
            }

            if (is_array($value)) {
                $findings = array_merge($findings, $this->inspectPatterns($where, $value, $current));
            }
        }

        return $findings;
    }

    private function inspectEnvelope(string $where, array $schema): array
    {
        $findings = [];

        if (($schema['type'] ?? null) !== 'object') {
            $findings[] = sprintf('%s: o envelope precisa ser `type: object`', $where);
        }

        if (($schema['additionalProperties'] ?? null) !== false) {
            $findings[] = sprintf('%s: envelope com `additionalProperties` diferente de false deixa o contrato aceitar campo novo em silêncio', $where);
        }

        $required = $schema['required'] ?? [];
        $missing = array_diff(self::ENVELOPE_REQUIRED, is_array($required) ? $required : []);

        if ($missing !== []) {
            $findings[] = sprintf('%s: envelope sem `required` para %s', $where, implode(', ', $missing));
        }

        return $findings;
    }

    private function inspectPayload(string $where, array $schema): array
    {
        $payload = $schema['properties']['payload'] ?? null;

        if (! is_array($payload)) {
            return [$where.': sem `properties.payload` — o envelope sem payload não descreve nada'];
        }

        $findings = [];

        if (($payload['type'] ?? null) !== 'object') {
            $findings[] = sprintf('%s: `payload` precisa ser `type: object`', $where);
        }

        if (($payload['additionalProperties'] ?? null) !== false) {
            $findings[] = sprintf('%s: `payload` com `additionalProperties` diferente de false aceita campo novo sem revisão do contrato', $where);
        }

        $required = is_array($payload['required'] ?? null) ? $payload['required'] : [];
        $properties = is_array($payload['properties'] ?? null) ? $payload['properties'] : [];

        foreach ($required as $field) {
            if (! array_key_exists($field, $properties)) {
                $findings[] = sprintf('%s: `payload.required` declara `%s` sem a propriedade correspondente', $where, $field);
            }
        }

        return $findings;
    }

    private function inspectParsable(string $where, string $raw): array
    {
        $validator = new Validator;

        try {
            $validator->loader()->loadObjectSchema(json_decode($raw));
        } catch (ParseException $exception) {
            return [$where.': schema não compila — '.$exception->getMessage()];
        }

        return [];
    }

    private function schemaFiles(): array
    {
        $files = glob(rtrim($this->contractsPath, '/').'/*.schema.json');

        if ($files === false || $files === []) {
            throw new \RuntimeException('nenhum schema encontrado em '.$this->contractsPath);
        }

        sort($files);

        return $files;
    }

    private function eventNameOf(string $file): string
    {
        return str_replace('.schema.json', '', basename($file));
    }
}
