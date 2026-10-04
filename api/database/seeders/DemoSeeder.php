<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Demo\DemoScenario;
use Illuminate\Database\Seeder;

/**
 * Cenário de demonstração — o que `db:seed --demo` monta.
 *
 * Depende do RBAC: as capabilities do proprietário e do morador são o que
 * decide o que a tela de cada um mostra, e sem elas o cenário entra num banco
 * onde todo morador nasce sem permissão nenhuma.
 */
class DemoSeeder extends Seeder
{
    private const LEGEND = [
        ['ana@mordomus.test', 'proprietária da Residência Vila Madalena'],
        ['bruno@mordomus.test', 'morador da Residência Vila Madalena'],
        ['carla@mordomus.test', 'moradora da Residência Vila Madalena'],
        ['diego@mordomus.test', 'proprietário do Apartamento Centro'],
        ['elisa@mordomus.test', 'moradora do Apartamento Centro'],
    ];

    public function run(DemoScenario $scenario): void
    {
        $this->call(RbacSeeder::class);

        $summary = $scenario->run();

        $this->command->newLine();
        $this->command->line(sprintf(
            'Cenário pronto: %d residência(s), %d morador(es), %d cômodo(s), %d item(ns), %d regra(s) de manutenção e %d conta(s).',
            $summary['tenants'],
            $summary['users'],
            $summary['rooms'],
            $summary['assets'],
            $summary['rules'],
            $summary['bills'],
        ));
        $this->command->line('Cada conta ganhou também a cadência de vencimento, que é o dono da agenda das contas.');
        $this->command->newLine();
        $this->command->line('Entrar em http://localhost:5173 com um destes moradores (senha para todos):');
        $this->command->newLine();

        foreach (self::LEGEND as [$email, $who]) {
            $this->command->line(sprintf('  %-24s %s', $email, $who));
        }

        $this->command->newLine();
    }
}
