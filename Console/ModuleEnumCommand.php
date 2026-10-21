<?php

namespace Studeo\Modular\Console;

use Studeo\Modular\Console\Actions\CreateEnumAction;
use Studeo\Modular\Console\Actions\ResolveEnumCasesAction;
use Studeo\Modular\Console\Actions\ResolveEnumNameAction;
use Studeo\Modular\Console\Prompts\ModulePicker;
use Throwable;

class ModuleEnumCommand extends ModuleCommand
{
    protected $signature = 'module:enum
        {name? : The enum name, e.g. PostStatus; it is always Enums/{Name}.php}
        {--module= : The name of the module}
        {--cases= : Comma-separated backed values in lower snake case, e.g. draft,published}';

    protected $description = 'Create a string-backed enum in a module, for use as status:enum(PostStatus) in a schema';

    public function handle(CreateEnumAction $creator, ResolveEnumNameAction $names, ResolveEnumCasesAction $cases, ModulePicker $picker): int
    {
        try {
            $module = $picker->resolve($this, $this->scalarOption('module'));

            $name = $this->resolveCheckedName(
                label: 'What should the enum be named?',
                placeholder: 'e.g. PostStatus',
                check: $names->execute(...),
                missingMessage: 'The enum name is required when the command is not interactive, e.g. PostStatus.',
            );
            $values = $this->resolveCheckedValue(
                given: $this->stringOption('cases'),
                label: 'What are the cases?',
                placeholder: 'e.g. draft,published',
                check: $cases->execute(...),
                missingMessage: 'The cases are required when the command is not interactive, e.g. '.ResolveEnumCasesAction::EXAMPLE.'. '.$cases->forms(),
            );

            $path = $creator->execute($module, $name, $values);
        } catch (Throwable $exception) {
            return $this->reportFailure($exception);
        }

        $this->displayCreated('Enum', basename($path, '.php'), $module->name, $path);

        return self::SUCCESS;
    }
}
