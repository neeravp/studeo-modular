<?php

namespace Studeo\Modular\Console;

use Studeo\Modular\Console\Actions\CreateActionAction;
use Studeo\Modular\Console\Actions\ResolveActionNameAction;
use Studeo\Modular\Console\Prompts\ModulePicker;
use Studeo\Modular\DataObjects\ActionGenerationOptions;
use Throwable;

class ModuleActionCommand extends ModuleCommand
{
    protected $signature = 'module:action
        {name? : The action name; Action is appended when missing}
        {--module= : The name of the module}
        {--system : Generate a #[SystemAction]: no authorization, callable only from Actions, Listeners and Jobs}
        {--schema= : A schema (@name, a file path, or inline): the Input takes its properties from it, and a Create* Action checks its unique and foreign key fields}';

    protected $description = 'Create a new action class and its paired Input in a module';

    public function handle(CreateActionAction $creator, ResolveActionNameAction $names, ModulePicker $picker): int
    {
        try {
            $module = $picker->resolve($this, $this->scalarOption('module'));

            $name = $this->resolveCheckedName(
                label: 'What should the action be named?',
                placeholder: 'e.g. CreatePost, PublishPostAction',
                check: $names->execute(...),
                missingMessage: 'The action name is required when the command is not interactive, e.g. CreatePost or CreatePostAction.',
            );

            $result = $creator->execute($module, $name, new ActionGenerationOptions(system: (bool) $this->option('system'), schema: $this->stringOption('schema')));
        } catch (Throwable $exception) {
            return $this->reportFailure($exception);
        }

        $this->displayCreated('Action', basename($result->actionPath, '.php'), $module->name, $result->actionPath);

        $result->inputCreated
            ? $this->displayCreated('Input', basename($result->inputPath, '.php'), $module->name, $result->inputPath)
            : $this->line('Using the existing Input at <comment>'.$result->inputPath.'</comment>.');

        array_map($this->line(...), $result->notices);

        return self::SUCCESS;
    }
}
