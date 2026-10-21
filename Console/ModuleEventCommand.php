<?php

namespace Studeo\Modular\Console;

use Studeo\Modular\Console\Actions\CreateEventAction;
use Studeo\Modular\Console\Prompts\ModulePicker;
use Throwable;

class ModuleEventCommand extends ModuleCommand
{
    protected $signature = 'module:event
        {name? : The name of the event}
        {--module= : The name of the module}';

    protected $description = 'Create a new event class in a module';

    public function handle(CreateEventAction $creator, ModulePicker $picker): int
    {
        try {
            $module = $picker->resolve($this, $this->scalarOption('module'));

            $name = $this->resolveEventName();

            $path = $creator->execute($module, $name);
        } catch (Throwable $exception) {
            return $this->reportFailure($exception);
        }

        $eventName = basename($path, '.php');
        $this->displayCreated('Event', $eventName, $module->name, $path);

        return self::SUCCESS;
    }

    private function resolveEventName(): string
    {
        return $this->resolveNameArgument(
            label: 'What should the event be named?',
            placeholder: 'e.g. OrderPlaced, FlightScheduled',
            example: 'OrderPlaced',
        );
    }
}
