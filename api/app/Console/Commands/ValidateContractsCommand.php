<?php

declare(strict_types=1);

namespace Mordomus\Console\Commands;

use Illuminate\Console\Command;
use Mordomus\Support\Contracts\ContractSchemaValidator;
use ReflectionClass;

class ValidateContractsCommand extends Command
{
    protected $signature = 'contracts:validate {--path= : Diretório dos JSON Schemas (padrão: packages/contracts)}';

    protected $description = 'Valida os JSON Schemas de packages/contracts contra os eventos publicados';

    public function handle(): int
    {
        $path = (string) ($this->option('path') ?: base_path('../packages/contracts'));

        if (! is_dir($path)) {
            $this->error('Diretório de contratos inexistente: '.$path);

            return self::FAILURE;
        }

        $published = $this->publishedEvents();

        if ($published === []) {
            $this->error('Nenhuma classe de evento encontrada em app/Modules/*/Events/EventName.php');

            return self::FAILURE;
        }

        $findings = (new ContractSchemaValidator($path))->inspect($published);

        if ($findings !== []) {
            foreach ($findings as $finding) {
                $this->error($finding);
            }

            $this->error(sprintf('%d problema(s) no contrato de eventos.', count($findings)));

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%d evento(s) com schema íntegro em %s',
            count($published),
            $path,
        ));

        return self::SUCCESS;
    }

    private function publishedEvents(): array
    {
        $events = [];

        foreach (glob(base_path('app/Modules/*/Events/EventName.php')) ?: [] as $file) {
            $class = 'Mordomus\\'.basename(dirname($file, 2)).'\\Events\\EventName';

            foreach ((new ReflectionClass($class))->getConstants() as $value) {
                if (is_string($value)) {
                    $events[$value][] = basename(dirname($file, 2));
                }
            }
        }

        ksort($events);

        return $events;
    }
}
