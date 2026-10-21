<?php

namespace Studeo\Modular\Console;

use Illuminate\Support\Str;
use Studeo\Modular\Console\Actions\CreateResourceAction;
use Studeo\Modular\Console\Prompts\ModulePicker;
use Throwable;

class ModuleResourceCommand extends ModuleCommand
{
    protected $signature = 'module:resource
        {name? : The name of the resource class}
        {--module= : The name of the module}
        {--collection : Create a resource collection}';

    protected $description = 'Create a new API resource or resource collection in a module';

    public function handle(CreateResourceAction $creator, ModulePicker $picker): int
    {
        try {
            $name = $this->resolveNameArgument(
                label: 'What is the name of the resource?',
                placeholder: 'e.g. FlightResource',
                example: 'FlightResource',
            );

            $module = $picker->resolve($this, $this->scalarOption('module'));
            $path = $creator->execute($module, $name, (bool) $this->option('collection'));
        } catch (Throwable $exception) {
            return $this->reportFailure($exception);
        }

        $resourceName = Str::beforeLast(basename($path), '.php');

        $this->displayCreated('Resource', $resourceName, $module->name);

        return self::SUCCESS;
    }
}
