<?php

namespace Studeo\Modular\Console;

use Illuminate\Console\Command;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\Console\Actions\ResolveModuleAction;
use Studeo\Modular\Console\Concerns\ChecksPrompting;
use Studeo\Modular\Console\Concerns\ReportsFailures;
use Studeo\Modular\Exceptions\NoTerminalException;
use Throwable;

use function Laravel\Prompts\text;

class ModuleMakeCommand extends Command
{
    use ChecksPrompting;
    use ReportsFailures;

    protected $signature = 'module:make {module?} {--root-namespace= : Namespace prefix for this module; empty means none (default: modular.root_namespace)}';

    protected $description = 'Create a new module.';

    public function handle(
        ResolveModuleAction $resolver,
        CreateModuleAction $creator,
    ): int {
        try {
            /** @var string|null $rootNamespace */
            $rootNamespace = $this->option('root-namespace');
            $module = $resolver->forNewModule($this->resolveModuleName(), $rootNamespace);
            $creator->execute($module);
        } catch (Throwable $exception) {
            return $this->reportFailure($exception);
        }

        $this->info(
            "Module [{$module->name}] created successfully."
        );

        return self::SUCCESS;
    }

    private function resolveModuleName(): string
    {
        /** @var string|null $name */
        $name = $this->argument('module');

        return $name ?? $this->promptForModuleName();
    }

    private function promptForModuleName(): string
    {
        if (! $this->canPrompt()) {
            throw new NoTerminalException('the [module] argument', "{$this->getName()} Account");
        }

        return text(
            label: 'What is the name of the module?',
            placeholder: 'e.g. Account',
            required: true,
        );
    }
}
