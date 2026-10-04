<?php

declare(strict_types=1);

namespace Mordomus\Phpstan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Nenhum módulo alcança a tabela de outro pelo query builder.
 *
 * Os cinco módulos dividem um banco só. Ler é o que mantém a fronteira de
 * contexto de pé sem barreira de rede: o módulo dono detém a coluna, o índice e
 * a regra que reage à escrita. Leitura de longe é permitida — pelas relações e
 * pelas classes de outro módulo, que é dado e não esquema — mas
 * `DB::table('bills')` dentro do Scheduling é o caminho curto que transforma
 * três services num rascunho de duas tabelas que ninguém migra junto.
 *
 * @implements Rule<Node\Expr\CallLike>
 */
final class CrossModuleTableRule implements Rule
{
    /** Tabela => módulo que a possui. */
    private const OWNED_TABLES = [
        // Identity
        'users' => 'Identity',
        'tenants' => 'Identity',
        'tenant_preferences' => 'Identity',
        'roles' => 'Identity',
        'permissions' => 'Identity',
        'role_permissions' => 'Identity',
        'memberships' => 'Identity',
        'membership_grants' => 'Identity',
        'invitations' => 'Identity',
        'refresh_tokens' => 'Identity',
        // Maintenance
        'rooms' => 'Maintenance',
        'assets' => 'Maintenance',
        // Financial
        'bills' => 'Financial',
        'bill_occurrences' => 'Financial',
        'payment_records' => 'Financial',
        'split_rules' => 'Financial',
        'split_entries' => 'Financial',
        'split_results' => 'Financial',
        // Scheduling
        'trigger_configs' => 'Scheduling',
        'job_schedules' => 'Scheduling',
        'schedule_events' => 'Scheduling',
        // Notification
        'device_tokens' => 'Notification',
        'notification_preferences' => 'Notification',
        'notification_logs' => 'Notification',
    ];

    private const MODULES = ['Identity', 'Maintenance', 'Financial', 'Scheduling', 'Notification'];

    /** Métodos cujo primeiro argumento nomeia a tabela. */
    private const TABLE_METHODS = ['table', 'from'];

    /** Fachada de acesso ao banco — a única chamada estática que nomeia tabela. */
    private const DATABASE_FACADES = ['DB', 'Connection'];

    public function getNodeType(): string
    {
        // `CallLike` alcança `DB::table()` e `connection()->table()`: são nós
        // diferentes (chamada estática e chamada de método) e a regra vale para
        // os dois.
        return Node\Expr\CallLike::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $method = $node->name;

        if (! $method instanceof Node\Identifier || ! in_array($method->toString(), self::TABLE_METHODS, true)) {
            return [];
        }

        if ($node instanceof Node\Expr\StaticCall && ! $this->isDatabaseFacade($node->class)) {
            return [];
        }

        $table = $this->literalTable($node->args[0] ?? null);

        if ($table === null) {
            return [];
        }

        $owner = self::OWNED_TABLES[$table] ?? null;
        $module = $this->moduleOf($scope->getNamespace());

        if ($owner === null || $module === null || $owner === $module) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'acesso direto à tabela `%s`, que pertence ao módulo %s: o módulo %s passa pelo repositório ou pelo service do dono',
                $table,
                $owner,
                $module,
            ))
                ->identifier('mordomus.crossModuleTable')
                ->build(),
        ];
    }

    private function isDatabaseFacade(Node $class): bool
    {
        if ($class instanceof Node\Name) {
            return in_array($class->getLast(), self::DATABASE_FACADES, true);
        }

        return false;
    }

    private function literalTable(?Node\Arg $argument): ?string
    {
        $value = $argument?->value;

        if (! $value instanceof Node\Scalar\String_) {
            return null;
        }

        return $value->value;
    }

    /** `Mordomus\Scheduling\Services` → `Scheduling`; fora dos módulos, nada a decidir. */
    private function moduleOf(?string $namespace): ?string
    {
        if ($namespace === null) {
            return null;
        }

        $parts = explode('\\', $namespace);

        if (count($parts) < 2 || $parts[0] !== 'Mordomus' || ! in_array($parts[1], self::MODULES, true)) {
            return null;
        }

        return $parts[1];
    }
}
