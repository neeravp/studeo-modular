<?php

namespace Studeo\Modular\Console;

use Studeo\Modular\Console\Actions\CreateCommandAction;
use Studeo\Modular\Console\Prompts\ModulePicker;
use Studeo\Modular\DataObjects\CommandGenerationOptions;
use Throwable;

class ModuleCommandCommand extends ModuleCommand
{
    protected $signature = 'module:command
        {name? : The name of the command}
        {--module= : The name of the module}
        {--command= : The terminal command that will be used to invoke the class}';

    protected $description = 'Create a new Artisan command class in a module';

    public function handle(CreateCommandAction $creator, ModulePicker $picker): int
    {
        try {
            $module = $picker->resolve($this, $this->scalarOption('module'));

            $name = $this->resolveCommandName();
            $options = new CommandGenerationOptions(
                command: filled($this->stringOption('command')) ? (string) $this->stringOption('command') : null,
            );

            $path = $creator->execute($module, $name, $options);
        } catch (Throwable $exception) {
            return $this->reportFailure($exception);
        }

        $commandName = basename($path, '.php');
        $this->displayCreated('Command', $commandName, $module->name, $path);

        return self::SUCCESS;
    }

    private function resolveCommandName(): string
    {
        return $this->resolveNameArgument(
            label: 'What should the command be named?',
            placeholder: 'e.g. SendEmails, SendEmailsCommand',
            example: 'SendEmails',
        );
    }
}
