<?php

namespace Studeo\Modular\Console;

use Studeo\Modular\Console\Actions\CreateFactoryAction;
use Studeo\Modular\Console\Prompts\ModulePicker;
use Throwable;

class ModuleFactoryCommand extends ModuleCommand
{
    protected $signature = 'module:factory
        {name? : The name of the factory}
        {--module= : The name of the module}
        {--model= : The name of the model}';

    protected $description = 'Create a new model factory in a module';

    public function handle(CreateFactoryAction $creator, ModulePicker $picker): int
    {
        try {
            $module = $picker->resolve($this, $this->scalarOption('module'));

            $name = $this->resolveFactoryName();
            $model = filled($this->stringOption('model')) ? (string) $this->stringOption('model') : null;

            $path = $creator->execute($module, $name, $model);
        } catch (Throwable $exception) {
            return $this->reportFailure($exception);
        }

        $factoryName = basename($path, '.php');
        $this->displayCreated('Factory', $factoryName, $module->name, $path);

        return self::SUCCESS;
    }

    private function resolveFactoryName(): string
    {
        return $this->resolveNameArgument(
            label: 'What should the factory be named?',
            placeholder: 'e.g. FlightFactory',
            example: 'FlightFactory',
        );
    }
}
