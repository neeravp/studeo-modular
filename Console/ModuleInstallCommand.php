<?php

namespace Studeo\Modular\Console;

use Illuminate\Console\Command;
use Studeo\Modular\Console\Actions\DiscoverModulesAction;
use Studeo\Modular\Console\Actions\InstallModuleAction;
use Studeo\Modular\Console\Actions\ResolveInstalledNamespaceAction;
use Studeo\Modular\Console\Actions\ResolveModuleAction;
use Studeo\Modular\Console\Concerns\ChecksPrompting;
use Studeo\Modular\Console\Concerns\ReportsFailures;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Throwable;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\spin;

class ModuleInstallCommand extends Command
{
    use ChecksPrompting;
    use ReportsFailures;

    protected $signature = 'module:install {module?} {--all : Install all pending modules without interactive selection}';

    protected $description = 'Install a specific module or all discovered modules.';

    public function handle(
        ResolveModuleAction $resolver,
        DiscoverModulesAction $discoverer,
        InstallModuleAction $installer,
        ResolveInstalledNamespaceAction $installedNamespace,
    ): int {
        try {
            /** @var string|null $moduleName */
            $moduleName = $this->argument('module');

            return $moduleName !== null
                ? $this->installTargeted($moduleName, $resolver, $installer)
                : $this->installDiscovered($discoverer, $installer, $installedNamespace);
        } catch (Throwable $exception) {
            return $this->reportFailure($exception);
        }
    }

    private function installTargeted(
        string $moduleName,
        ResolveModuleAction $resolver,
        InstallModuleAction $installer,
    ): int {
        $module = $resolver->execute($moduleName);

        $result = spin(
            callback: fn () => $installer->execute($module),
            message: "Installing module [{$module->name}]...",
        );

        if (in_array($module->name, $result->alreadyInstalled, true)) {
            $this->info("Module [{$module->name}] is already installed.");

            return self::SUCCESS;
        }

        $this->info("Module [{$module->name}] installed successfully.");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $skipped  directory name => reason
     */
    private function displaySkippedDirectories(array $skipped): void
    {
        foreach ($skipped as $name => $reason) {
            $this->warn("Skipped [{$name}]: {$reason}");
        }
    }

    private function installDiscovered(
        DiscoverModulesAction $discoverer,
        InstallModuleAction $installer,
        ResolveInstalledNamespaceAction $installedNamespace,
    ): int {
        $modules = $discoverer->execute();

        $this->displaySkippedDirectories($discoverer->skipped());

        if (empty($modules)) {
            $this->info('No modules found in ['.config('modular.root_path').'].');

            return self::SUCCESS;
        }

        $pending = array_filter($modules, fn ($module): bool => $installedNamespace->execute($module) === null);

        if (empty($pending)) {
            $this->info('All modules are already installed.');

            return self::SUCCESS;
        }

        return $this->installChosen($pending, $installer);
    }

    /**
     * @param  array<string, ModuleDefinition>  $pending
     */
    private function installChosen(array $pending, InstallModuleAction $installer): int
    {
        $selectedModules = $this->chooseModules($pending);

        $result = spin(
            callback: fn () => $installer->execute(...array_values($selectedModules)),
            message: 'Installing module(s) and dumping autoloads...',
        );

        foreach ($result->installed as $installedModule) {
            $this->info("Module [{$installedModule}] installed successfully.");
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, ModuleDefinition>  $pending
     * @return array<string, ModuleDefinition>
     */
    private function chooseModules(array $pending): array
    {
        if ($this->installsEveryPendingModule()) {
            return $pending;
        }

        $selectedNames = multiselect(
            label: 'Which module(s) would you like to install?',
            options: array_keys($pending),
            default: array_keys($pending),
            required: true,
        );

        return array_intersect_key($pending, array_flip($selectedNames));
    }

    private function installsEveryPendingModule(): bool
    {
        return ! $this->canPrompt() || $this->option('all');
    }
}
