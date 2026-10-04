<?php

namespace Tests\Unit;

use Mordomus\Support\Contracts\ContractSchemaValidator;
use RuntimeException;
use Tests\TestCase;

/**
 * O portão do contrato só vale se ele próprio for testado: uma conferência que
 * engole erro e devolve "tudo certo" reprova o PR errado e aprova o quebrado.
 */
class ContractSchemaValidatorTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/mordomus-contracts-'.bin2hex(random_bytes(6));
        mkdir($this->path, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->path.'/*.schema.json') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->path)) {
            rmdir($this->path);
        }

        parent::tearDown();
    }

    public function test_a_complete_contract_has_no_findings(): void
    {
        $this->write('bill.due', ['tenant_id', 'amount']);

        $this->assertSame([], $this->validator()->inspect(['bill.due' => ['Financial']]));
    }

    public function test_event_published_without_schema_is_reported(): void
    {
        $this->write('bill.due', ['tenant_id']);

        $findings = $this->validator()->inspect([
            'bill.due' => ['Financial'],
            'bill.paid' => ['Financial'],
        ]);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('bill.paid', $findings[0]);
        $this->assertStringContainsString('não tem schema', $findings[0]);
    }

    public function test_schema_without_published_event_is_reported(): void
    {
        $this->write('bill.due', ['tenant_id']);
        $this->write('bill.paid', ['amount']);

        $findings = $this->validator()->inspect(['bill.due' => ['Financial']]);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('bill.paid.schema.json', $findings[0]);
    }

    public function test_envelope_that_accepts_unknown_field_is_reported(): void
    {
        $this->write('bill.due', ['tenant_id'], ['envelope_additional_properties' => true]);

        $findings = $this->validator()->inspect(['bill.due' => ['Financial']]);

        $this->assertNotSame([], $findings);
        $this->assertStringContainsString('additionalProperties', $findings[0]);
    }

    public function test_payload_without_its_declared_property_is_reported(): void
    {
        $this->write('bill.due', ['tenant_id'], ['required_without_property' => 'paid_at']);

        $findings = $this->validator()->inspect(['bill.due' => ['Financial']]);

        $this->assertNotSame([], $findings);
        $this->assertStringContainsString('paid_at', $findings[0]);
    }

    public function test_broken_pattern_is_reported(): void
    {
        $this->write('bill.due', ['tenant_id'], ['tenant_pattern' => '[fechamento_que_falta']);

        $findings = $this->validator()->inspect(['bill.due' => ['Financial']]);

        $this->assertNotSame([], $findings);
        $this->assertStringContainsString('expressão regular', $findings[0]);
    }

    public function test_name_that_diverges_from_the_file_is_reported(): void
    {
        $this->write('bill.due', ['tenant_id'], ['title' => 'bill.paga']);

        $findings = $this->validator()->inspect(['bill.due' => ['Financial']]);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('`title`', $findings[0]);
    }

    public function test_envelope_naming_another_event_is_reported(): void
    {
        $this->write('bill.due', ['tenant_id'], ['event_const' => 'bill.due.v2']);

        $findings = $this->validator()->inspect(['bill.due' => ['Financial']]);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('properties.event.const', $findings[0]);
    }

    public function test_malformed_json_is_reported_without_throwing(): void
    {
        file_put_contents($this->path.'/bill.due.schema.json', '{"type": ');

        $findings = $this->validator()->inspect(['bill.due' => ['Financial']]);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('JSON inválido', $findings[0]);
    }

    public function test_empty_directory_is_not_silently_valid(): void
    {
        $this->expectException(RuntimeException::class);

        $this->validator()->inspect([]);
    }

    private function validator(): ContractSchemaValidator
    {
        return new ContractSchemaValidator($this->path);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function write(string $event, array $payloadFields, array $options = []): void
    {
        $properties = ['tenant_id' => ['type' => 'string']];

        foreach ($payloadFields as $field) {
            $properties[$field] = ['type' => 'string'];
        }

        $required = $payloadFields;

        if (isset($options['required_without_property'])) {
            $required[] = $options['required_without_property'];
        }

        if (isset($options['tenant_pattern'])) {
            $properties['tenant_id']['pattern'] = $options['tenant_pattern'];
        }

        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => 'https://mordomus.app/contracts/'.$event.'.schema.json',
            'title' => $options['title'] ?? $event,
            'type' => 'object',
            'additionalProperties' => $options['envelope_additional_properties'] ?? false,
            'required' => ['event', 'event_id', 'occurred_at', 'tenant_id', 'payload'],
            'properties' => [
                'event' => ['const' => $options['event_const'] ?? $event],
                'event_id' => ['type' => 'string'],
                'occurred_at' => ['type' => 'string', 'format' => 'date-time'],
                'tenant_id' => ['type' => 'string'],
                'payload' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => $required,
                    'properties' => $properties,
                ],
            ],
        ];

        file_put_contents(
            $this->path.'/'.$event.'.schema.json',
            json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }
}
