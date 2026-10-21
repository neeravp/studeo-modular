<?php

namespace Studeo\Modular\Providers;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;
use Studeo\Modular\Console\Actions\DiscoverInstalledModulesAction;
use Studeo\Modular\Console\Actions\EnsureProviderClassLoadableAction;
use Studeo\Modular\Console\Actions\ReportModuleLoadFailuresAction;
use Studeo\Modular\Console\ModularCacheCommand;
use Studeo\Modular\Console\ModularClearCommand;
use Studeo\Modular\Console\ModularOptimizeCommand;
use Studeo\Modular\Console\ModuleActionCommand;
use Studeo\Modular\Console\ModuleCheckCommand;
use Studeo\Modular\Console\ModuleCommandCommand;
use Studeo\Modular\Console\ModuleControllerCommand;
use Studeo\Modular\Console\ModuleDtoCommand;
use Studeo\Modular\Console\ModuleEnumCommand;
use Studeo\Modular\Console\ModuleEventCommand;
use Studeo\Modular\Console\ModuleFactoryCommand;
use Studeo\Modular\Console\ModuleInstallCommand;
use Studeo\Modular\Console\ModuleJobCommand;
use Studeo\Modular\Console\ModuleListenerCommand;
use Studeo\Modular\Console\ModuleLivewireCommand;
use Studeo\Modular\Console\ModuleLivewireDeleteCommand;
use Studeo\Modular\Console\ModuleLivewireMoveCommand;
use Studeo\Modular\Console\ModuleMailCommand;
use Studeo\Modular\Console\ModuleMakeCommand;
use Studeo\Modular\Console\ModuleMiddlewareCommand;
use Studeo\Modular\Console\ModuleMigrationCommand;
use Studeo\Modular\Console\ModuleModelCommand;
use Studeo\Modular\Console\ModuleNotificationCommand;
use Studeo\Modular\Console\ModulePivotCommand;
use Studeo\Modular\Console\ModulePolicyCommand;
use Studeo\Modular\Console\ModuleRemoveCommand;
use Studeo\Modular\Console\ModuleResourceCommand;
use Studeo\Modular\Console\ModuleRuleCommand;
use Studeo\Modular\Console\ModuleSchemaCheckCommand;
use Studeo\Modular\Console\ModuleSchemaCommand;
use Studeo\Modular\Console\ModuleSeederCommand;
use Studeo\Modular\Console\ModuleUninstallCommand;
use Studeo\Modular\Console\ModuleVueCommand;
use Studeo\Modular\Console\ModuleVueDeleteCommand;
use Studeo\Modular\Console\ModuleVueMoveCommand;
use Studeo\Modular\DataObjects\InstalledModule;
use Studeo\Modular\Exceptions\ModuleLoadException;
use Studeo\Modular\Exceptions\ModuleRegistrationException;
use Studeo\Modular\Support\ModuleCache;
use Studeo\Modular\Support\ModuleLoadFailures;
use Studeo\Modular\Support\SchemaWarnings;

class ModularServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/modular.php',
            'modular'
        );

        $this->app->singleton(ModuleLoadFailures::class);
        $this->app->singleton(SchemaWarnings::class);
        $this->app->singleton(ModuleCache::class, fn ($app): ModuleCache => new ModuleCache(
            $app->bootstrapPath('cache/modular.php'),
            $app->make(Filesystem::class),
        ));

        $this->registerInstalledModules();
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/modular.php' => config_path('modular.php'),
        ], 'modular-config');

        $this->optimizes(optimize: 'modular:optimize', clear: 'modular:clear');

        $this->commands([
            ModularCacheCommand::class,
            ModularClearCommand::class,
            ModularOptimizeCommand::class,
            ModuleMakeCommand::class,
            ModuleInstallCommand::class,
            ModuleUninstallCommand::class,
            ModuleRemoveCommand::class,
            ModuleModelCommand::class,
            ModulePolicyCommand::class,
            ModuleResourceCommand::class,
            ModuleControllerCommand::class,
            ModulePivotCommand::class,
            ModuleMigrationCommand::class,
            ModuleFactoryCommand::class,
            ModuleSchemaCheckCommand::class,
            ModuleSchemaCommand::class,
            ModuleSeederCommand::class,
            ModuleEventCommand::class,
            ModuleListenerCommand::class,
            ModuleJobCommand::class,
            ModuleCheckCommand::class,
            ModuleCommandCommand::class,
            ModuleMiddlewareCommand::class,
            ModuleNotificationCommand::class,
            ModuleMailCommand::class,
            ModuleRuleCommand::class,
            ModuleLivewireCommand::class,
            ModuleLivewireDeleteCommand::class,
            ModuleLivewireMoveCommand::class,
            ModuleActionCommand::class,
            ModuleDtoCommand::class,
            ModuleEnumCommand::class,
            ModuleVueCommand::class,
            ModuleVueDeleteCommand::class,
            ModuleVueMoveCommand::class,
        ]);
    }

    protected function registerInstalledModules(): void
    {
        $discovered = $this->app->make(DiscoverInstalledModulesAction::class)->discover();

        $failures = $this->app->make(ModuleLoadFailures::class);

        foreach ($discovered->failures as $failure) {
            $failures->add($failure);
        }

        foreach ($discovered->installed as $name => $installed) {
            $this->registerModuleProvider($name, $installed, $failures);
        }

        $this->reportModuleFailures($failures);
    }

    /**
     * A provider class that cannot be autoloaded is a load failure and is not registered.
     * Register-phase registrar failures are recorded like boot-phase ones, which the
     * registrar records itself. Any other exception from a module's provider propagates.
     */
    protected function registerModuleProvider(string $name, InstalledModule $installed, ModuleLoadFailures $failures): void
    {
        try {
            $this->app->make(EnsureProviderClassLoadableAction::class)->execute($installed);
            $this->app->register($installed->manifest->provider());
        } catch (ModuleLoadException $exception) {
            $failures->add($exception);
        } catch (ModuleRegistrationException $exception) {
            $failures->add(ModuleLoadException::registrationFailed($name, $exception));
        }
    }

    /**
     * Deferred until the app has booted, when every module's boot-phase failures
     * are in the collector and the exception handler can render or report.
     */
    protected function reportModuleFailures(ModuleLoadFailures $failures): void
    {
        $this->app->booted(function () use ($failures): void {
            $this->app->make(ReportModuleLoadFailuresAction::class)
                ->execute($failures->all(), $this->app->runningInConsole());
        });
    }
}
