<?php

declare(strict_types=1);

namespace Mordomus\Console\Commands;

use Database\Seeders\DemoSeeder;
use Illuminate\Database\Console\Seeds\SeedCommand as FrameworkSeedCommand;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'db:seed')]
final class SeedCommand extends FrameworkSeedCommand
{
    protected $signature = 'db:seed
                    {class? : The class name of the root seeder}
                    {--class=Database\\Seeders\\DatabaseSeeder : The class name of the root seeder}
                    {--demo : Semeia o cenário de demonstração (residências, inventário, agenda e contas)}
                    {--database= : The database connection to seed}
                    {--force : Force the operation to run when in production}';

    public function handle()
    {
        if ($this->option('demo')) {
            $this->input->setArgument('class', DemoSeeder::class);
            $this->input->setOption('class', DemoSeeder::class);
        }

        return parent::handle();
    }
}
