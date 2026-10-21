<?php

namespace Studeo\Modular\Console;

use Studeo\Modular\Console\Actions\CreateSeederAction;
use Studeo\Modular\Console\Prompts\ModulePicker;
use Throwable;

class ModuleSeederCommand extends ModuleCommand
{
    protected $signature = 'module:seeder
        {name? : The name of the seeder}
        {--module= : The name of the module}
        {--model= : The name of the model}';

    protected $description = 'Create a new database seeder in a module';

    public function handle(CreateSeederAction $creator, ModulePicker $picker): int
    {
        try {
            $module = $picker->resolve($this, $this->scalarOption('module'));

            $name = $this->resolveSeederName();
            $model = filled($this->stringOption('model')) ? (string) $this->stringOption('model') : null;

            $path = $creator->execute($module, $name, $model);
        } catch (Throwable $exception) {
            return $this->reportFailure($exception);
        }

        $seederName = basename($path, '.php');
        $this->displayCreated('Seeder', $seederName, $module->name, $path);

        return self::SUCCESS;
    }

    private function resolveSeederName(): string
    {
        return $this->resolveNameArgument(
            label: 'What should the seeder be named?',
            placeholder: 'e.g. FlightSeeder',
            example: 'FlightSeeder',
        );
    }
}
