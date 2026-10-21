<?php

namespace Studeo\Modular\Tests;

use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Orchestra\Testbench\TestCase as Orchestra;
use Studeo\Modular\Console\Actions\CreateEnumAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\ModuleManifest;
use Studeo\Modular\Providers\ModularServiceProvider;

abstract class TestCase extends Orchestra
{
    /** @var array<int, callable> */
    private array $moduleAutoloaders = [];

    protected function getPackageProviders($app): array
    {
        return [
            ModularServiceProvider::class,
        ];
    }

    /**
     * No test can wait on the terminal: see NonInteractiveKernel.
     */
    protected function applicationConsoleKernelUsingWorkbench($app): string
    {
        return NonInteractiveKernel::class;
    }

    protected function getEnvironmentSetUp($app): void
    {
        // “Tests delete this directory; keep it inside the testbench skeleton”
        $app['config']->set(
            'modular.root_path',
            $app->basePath('modules')
        );

        // Most tests assert the prefixed form; the package default (no prefix) has its own tests.
        $app['config']->set(
            'modular.root_namespace',
            'Studeo'
        );
    }

    /**
     * Write a valid module.json for a module directory (created if missing).
     */
    public function writeModuleManifest(string $modulePath, string $name, string $namespace): void
    {
        $this->writeRawModuleManifest($modulePath, (new ModuleManifest($name, $namespace))->toJson());
    }

    /**
     * Write arbitrary module.json contents, for invalid-manifest cases.
     */
    public function writeRawModuleManifest(string $modulePath, string $contents): void
    {
        File::ensureDirectoryExists($modulePath);
        File::put($modulePath.'/'.ModuleManifest::FILE, $contents);
    }

    /**
     * Autoload a module's classes the way `composer dump-autoload` would after install.
     */
    public function autoloadModule(string $namespace, string $directory): void
    {
        $prefix = $namespace.'\\';

        $loader = function (string $class) use ($prefix, $directory): void {
            $file = $directory.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

            if (str_starts_with($class, $prefix) && is_file($file)) {
                require_once $file;
            }
        };

        spl_autoload_register($loader);
        $this->moduleAutoloaders[] = $loader;
    }

    /**
     * Hash every file a generator must never touch: the root app's own code
     * and configuration, outside the modules directory.
     *
     * @return array<string, string>
     */
    public function rootAppSnapshot(): array
    {
        $snapshot = [];

        foreach (['app', 'bootstrap', 'config', 'routes'] as $directory) {
            if (! File::isDirectory(base_path($directory))) {
                continue;
            }

            foreach (File::allFiles(base_path($directory)) as $file) {
                if (! str_contains($file->getPathname(), '/bootstrap/cache/')) {
                    $snapshot[$file->getPathname()] = md5_file($file->getPathname());
                }
            }
        }

        return $snapshot;
    }

    /**
     * Replace Livewire's manager with a recorder, so registration can be observed without booting
     * Livewire. Set `failing` to make registering those aliases, or namespaces, throw.
     */
    public function fakeLivewire(): object
    {
        $recorder = new class
        {
            /** @var array<string, string> alias => class name, or view path for a view-based component */
            public array $registered = [];

            /** @var array<string, array{viewPath: ?string, classNamespace: ?string}> */
            public array $namespaces = [];

            /** @var array<int, string> */
            public array $failing = [];

            public function component(string $alias, string $class): void
            {
                $this->record($alias, $class);
            }

            public function addComponent(string $alias, ?string $viewPath = null, ?string $class = null): void
            {
                $this->record($alias, $viewPath ?? (string) $class);
            }

            public function addNamespace(string $namespace, ?string $viewPath = null, ?string $classNamespace = null): void
            {
                in_array($namespace, $this->failing, true)
                    ? throw new \RuntimeException("cannot register {$namespace}")
                    : $this->namespaces[$namespace] = ['viewPath' => $viewPath, 'classNamespace' => $classNamespace];
            }

            private function record(string $alias, string $target): void
            {
                in_array($alias, $this->failing, true)
                    ? throw new \RuntimeException("cannot register {$alias}")
                    : $this->registered[$alias] = $target;
            }
        };

        Livewire::swap($recorder);

        return $recorder;
    }

    /**
     * Generate and install a module with a command-free mix of cacheable artifacts:
     * an event, a listener, a model with its policy, and a middleware.
     */
    public function generateCacheableModule(string $module): void
    {
        $this->artisan('module:make', ['module' => $module])->assertSuccessful();
        $this->autoloadModule("Studeo\\{$module}", config('modular.root_path').'/'.$module);

        foreach ([
            ['module:event', ['name' => 'OrderPlaced']],
            ['module:listener', ['name' => 'SendReceipt', '--event' => 'OrderPlaced']],
            ['module:model', ['name' => 'Flight', '--no-migration' => true, '--no-factory' => true, '--no-seeder' => true]],
            ['module:policy', ['name' => 'FlightPolicy', '--model' => 'Flight']],
            ['module:middleware', ['name' => 'EnsureActive']],
        ] as [$command, $arguments]) {
            $this->artisan($command, ['--module' => $module, ...$arguments])->assertSuccessful();
        }

        $this->artisan('module:install', ['module' => $module])->assertSuccessful();
    }

    /**
     * Write a string-backed enum into a module's Enums/ with the generator behind module:enum.
     *
     * @param  array<int, string>  $values
     */
    public function writeEnumFixture(ModuleDefinition $module, string $name, array $values = ['draft', 'published']): void
    {
        app(CreateEnumAction::class)->execute($module, $name, implode(',', $values));
    }

    public function moduleCachePath(): string
    {
        return $this->app->bootstrapPath('cache/modular.php');
    }

    protected function tearDown(): void
    {
        File::delete($this->moduleCachePath());

        foreach ($this->moduleAutoloaders as $loader) {
            spl_autoload_unregister($loader);
        }

        if ($this->moduleAutoloaders !== []) {
            // Artisan::starting() callbacks are static; drop the ones this test registered.
            ConsoleApplication::forgetBootstrappers();
            $this->moduleAutoloaders = [];
        }

        parent::tearDown();
    }
}
