<?php

namespace Studeo\Modular\Console;

use Studeo\Modular\Console\Actions\CreateListenerAction;
use Studeo\Modular\Console\Prompts\ModulePicker;
use Studeo\Modular\DataObjects\ListenerGenerationOptions;
use Throwable;

class ModuleListenerCommand extends ModuleCommand
{
    protected $signature = 'module:listener
        {name? : The name of the listener}
        {--module= : The name of the module}
        {--event= : The event class to listen for}
        {--queued : Mark the listener as queued (implements ShouldQueue)}';

    protected $description = 'Create a new event listener class in a module';

    public function handle(CreateListenerAction $creator, ModulePicker $picker): int
    {
        try {
            $module = $picker->resolve($this, $this->scalarOption('module'));

            $name = $this->resolveListenerName();
            $options = new ListenerGenerationOptions(
                event: filled($this->stringOption('event')) ? (string) $this->stringOption('event') : null,
                queued: (bool) $this->option('queued'),
            );

            $path = $creator->execute($module, $name, $options);
        } catch (Throwable $exception) {
            return $this->reportFailure($exception);
        }

        $listenerName = basename($path, '.php');
        $this->displayCreated('Listener', $listenerName, $module->name, $path);

        return self::SUCCESS;
    }

    private function resolveListenerName(): string
    {
        return $this->resolveNameArgument(
            label: 'What should the listener be named?',
            placeholder: 'e.g. SendOrderNotification, OrderPlacedListener',
            example: 'SendOrderNotification',
        );
    }
}
