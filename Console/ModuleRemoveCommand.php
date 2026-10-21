<?php

namespace Studeo\Modular\Console;

use Illuminate\Console\Command;
use Studeo\Modular\Console\Actions\RemoveModuleAction;
use Studeo\Modular\Console\Actions\ResolveInstalledNamespaceAction;
use Studeo\Modular\Console\Actions\ResolveModuleAction;
use Studeo\Modular\Console\Concerns\ChecksPrompting;
use Studeo\Modular\Console\Concerns\ReportsFailures;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Exceptions\NoTerminalException;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\warning;

class ModuleRemoveCommand extends Command
{
    use ChecksPrompting;
    use ReportsFailures;

    protected $signature = 'module:remove {module} {--force : Force the operation without confirmation}';

    protected $description = 'Permanently remove a module from disk.';

    public function handle(
        ResolveModuleAction $resolver,
        RemoveModuleAction $remover,
        ResolveInstalledNamespaceAction $installedNamespace,
    ): int {
        try {
            /** @var string $moduleName */
            $moduleName = $this->argument('module');
            $module = $resolver->forRepair($moduleName);

            return $installedNamespace->execute($module) !== null
                ? $this->refuseInstalled($module)
                : $this->removeWhenConfirmed($module, $remover);
        } catch (Throwable $exception) {
            return $this->reportFailure($exception);
        }
    }

    private function refuseInstalled(ModuleDefinition $module): int
    {
        $this->error(
            "Cannot remove module [{$module->name}] because it is currently installed. Run [php artisan module:uninstall {$module->name}] first."
        );

        return self::FAILURE;
    }

    private function removeWhenConfirmed(ModuleDefinition $module, RemoveModuleAction $remover): int
    {
        if (! $this->deletionConfirmed($module)) {
            $this->info("Module [{$module->name}] was not removed.");

            return self::SUCCESS;
        }

        $remover->execute($module);

        $this->info("Module [{$module->name}] removed successfully.");

        return self::SUCCESS;
    }

    private function deletionConfirmed(ModuleDefinition $module): bool
    {
        if ($this->option('force')) {
            return true;
        }

        if (! $this->canPrompt()) {
            throw new NoTerminalException('the --force option', "{$this->getName()} {$module->name} --force");
        }

        warning("This will permanently delete the module [{$module->name}] and all its contents.");

        return confirm(
            label: "Are you sure you want to delete module [{$module->name}]?",
            default: false,
        );
    }
}
