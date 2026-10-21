<?php

namespace Studeo\Modular\Console;

use Illuminate\Console\Command;
use Studeo\Modular\Console\Actions\ResolveModuleAction;
use Studeo\Modular\Console\Actions\UninstallModuleAction;
use Studeo\Modular\Console\Concerns\ChecksPrompting;
use Studeo\Modular\Console\Concerns\ReportsFailures;
use Studeo\Modular\Exceptions\NoTerminalException;
use Studeo\Modular\Support\RootComposer;
use Throwable;

use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;

class ModuleUninstallCommand extends Command
{
    use ChecksPrompting;
    use ReportsFailures;

    protected $signature = 'module:uninstall {module?}';

    protected $description = 'Uninstall and unregister a module.';

    public function handle(
        ResolveModuleAction $resolver,
        UninstallModuleAction $uninstaller,
        RootComposer $rootComposer,
    ): int {
        try {
            $moduleName = $this->argument('module');

            return $moduleName === null
                ? $this->uninstallChosen($rootComposer, $resolver, $uninstaller)
                : $this->uninstallNamed($moduleName, $resolver, $uninstaller);
        } catch (Throwable $exception) {
            return $this->reportFailure($exception);
        }
    }

    private function uninstallChosen(
        RootComposer $rootComposer,
        ResolveModuleAction $resolver,
        UninstallModuleAction $uninstaller,
    ): int {
        $installedModules = $rootComposer->installedModules();

        if (empty($installedModules)) {
            $this->info('No modules are currently installed.');

            return self::SUCCESS;
        }

        if (! $this->canPrompt()) {
            throw new NoTerminalException('the [module] argument', "{$this->getName()} {$installedModules[0]}");
        }

        $moduleName = select(
            label: 'Which module would you like to uninstall?',
            options: $installedModules,
        );

        return $this->uninstallNamed($moduleName, $resolver, $uninstaller);
    }

    /**
     * Untyped on purpose: the name arrives as given or as the select answered it, and forRepair()
     * alone decides whether it is a usable string.
     */
    private function uninstallNamed(mixed $moduleName, ResolveModuleAction $resolver, UninstallModuleAction $uninstaller): int
    {
        $module = $resolver->forRepair($moduleName);

        $uninstalled = spin(
            callback: fn () => $uninstaller->execute($module),
            message: "Uninstalling module [{$module->name}]...",
        );

        if (! $uninstalled) {
            $this->info("Module [{$module->name}] is not installed.");

            return self::SUCCESS;
        }

        $this->info("Module [{$module->name}] uninstalled successfully.");

        return self::SUCCESS;
    }
}
