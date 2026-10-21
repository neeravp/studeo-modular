<?php

namespace Studeo\Modular\Support;

use Closure;
use Exception;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;
use Studeo\Modular\DataObjects\ModuleManifest;
use Studeo\Modular\Exceptions\InvalidModuleManifestException;
use Studeo\Modular\Exceptions\ModuleLoadException;
use Studeo\Modular\Exceptions\ModuleRegistrationException;

/**
 * Registers a module's own artifacts explicitly, from the module's own provider.
 * Each step is one concern; a module drops a step by removing it from its provider.
 * Nothing relies on Laravel's conventional discovery paths.
 *
 * config() belongs in the provider's register(): a failure there propagates to
 * ModularServiceProvider. Every other step belongs in boot(): a failing step is
 * recorded in the shared ModuleLoadFailures and the remaining steps still run.
 */
final class ModuleRegistrar
{
    private function __construct(
        private readonly ServiceProvider $provider,
        private readonly string $directory,
        private readonly ModuleManifest $manifest,
        private readonly Filesystem $files,
        private readonly ModuleScanner $scanner,
    ) {}

    public static function for(ServiceProvider $provider, string $directory): self
    {
        $files = new Filesystem;

        try {
            $manifest = (new ResolveModuleManifestAction($files))->forDirectory($directory);
        } catch (InvalidModuleManifestException $exception) {
            throw ModuleRegistrationException::invalidManifest($directory, $exception);
        }

        $cache = (fn (): ModuleCache => $this->app->make(ModuleCache::class))->call($provider);

        return new self($provider, $directory, $manifest, $files, new ModuleScanner($directory, $manifest, $files, $cache->entryFor($manifest->key())));
    }

    public function config(): self
    {
        $file = $this->path('config/'.$this->manifest->key().'.php');
        $key = $this->manifest->key();

        return $this->onProviderIf($this->files->isFile($file), fn () => $this->mergeConfigFrom($file, $key));
    }

    /**
     * Deferred until the app has booted, so Route::pattern() and similar settings
     * made in the app's own providers apply to module routes.
     */
    public function routes(): self
    {
        $routes = array_values(array_filter([$this->path('routes/web.php'), $this->path('routes/api.php')], $this->files->isFile(...)));

        return $this->guarded(fn () => $this->app()->booted(
            fn () => collect($routes)->each(fn (string $file) => $this->onProviderIf(true, fn () => $this->loadRoutesFrom($file)))
        ));
    }

    public function views(): self
    {
        $directory = $this->path('resources/views');
        $key = $this->manifest->key();

        return $this->guarded(fn () => $this->onProviderIf($this->files->isDirectory($directory), fn () => $this->loadViewsFrom($directory, $key)));
    }

    public function migrations(): self
    {
        $directory = $this->path('database/migrations');

        return $this->guarded(fn () => $this->onProviderIf($this->files->isDirectory($directory), fn () => $this->loadMigrationsFrom($directory)));
    }

    public function translations(): self
    {
        $directory = $this->path('lang');
        $key = $this->manifest->key();

        return $this->guarded(fn () => $this->onProviderIf($this->files->isDirectory($directory), function () use ($directory, $key) {
            $this->loadTranslationsFrom($directory, $key);
            $this->loadJsonTranslationsFrom($directory);
        }));
    }

    public function commands(): self
    {
        $commands = $this->app()->runningInConsole() ? $this->scanner->commands() : [];

        return $this->guarded(fn () => $this->onProviderIf($commands !== [], fn () => $this->commands($commands)));
    }

    public function listeners(): self
    {
        return $this->guarded(function () {
            foreach ($this->scanner->listeners() as $event => $handlers) {
                $this->listen($event, $handlers);
            }
        });
    }

    public function policies(): self
    {
        return $this->guarded(function () {
            foreach ($this->scanner->policies() as $model => $policy) {
                $this->app()->make(Gate::class)->policy($model, $policy);
            }
        });
    }

    /**
     * One alias at a time, so a colliding alias does not stop the others.
     */
    public function middleware(): self
    {
        foreach ($this->scanner->middleware() as $alias => $class) {
            $this->guarded(fn () => $this->aliasMiddleware($alias, $class));
        }

        return $this;
    }

    /** Only when Livewire is installed. The namespace and each component are items, like middleware aliases. */
    public function livewire(): self
    {
        $components = $this->scanner->livewire($this->app()->make(LivewireComponentScanner::class));

        foreach (LivewireRegistrations::for($this->manifest, $this->path('Livewire'), $components) as $item => $register) {
            $this->guarded(fn () => $this->registerLivewireItem($item, $register));
        }

        return $this;
    }

    private function registerLivewireItem(string $item, Closure $register): void
    {
        try {
            $register();
            // Exception is the narrowest type here: Livewire 4's finder throws a bare \Exception, and the same call reaches container errors.
        } catch (Exception $exception) {
            throw ModuleRegistrationException::livewireComponentFailed($this->manifest->name, $item, $exception);
        }
    }

    /**
     * @param  array<int, string>  $handlers
     */
    private function listen(string $event, array $handlers): void
    {
        foreach ($handlers as $handler) {
            $this->listenOnce($event, $handler);
        }
    }

    private function listenOnce(string $event, string $handler): void
    {
        $events = $this->app()->make('events');

        if (! in_array($handler, $events->getRawListeners()[$event] ?? [], true)) {
            $events->listen($event, $handler);
        }
    }

    private function aliasMiddleware(string $alias, string $class): void
    {
        $router = $this->app()->make('router');
        $existing = $router->getMiddleware()[$alias] ?? $class;

        if ($existing !== $class) {
            throw ModuleRegistrationException::middlewareAliasTaken($this->manifest->name, $alias, $existing, $class);
        }

        $router->aliasMiddleware($alias, $class);
    }

    private function path(string $relative): string
    {
        return rtrim($this->directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$relative;
    }

    /** ServiceProvider::$app is protected, so read it from the provider's own scope. */
    private function app(): Application
    {
        return (fn (): Application => $this->app)->call($this->provider);
    }

    /**
     * Run a boot-phase step. A registration failure is recorded and the next
     * step still runs; any other exception propagates.
     */
    private function guarded(Closure $step): self
    {
        try {
            $step();
        } catch (ModuleRegistrationException $exception) {
            $this->app()->make(ModuleLoadFailures::class)->add(
                ModuleLoadException::registrationFailed($this->manifest->name, $exception)
            );
        }

        return $this;
    }

    /**
     * Run the callback with the provider as $this, so it can use the provider's own
     * loadRoutesFrom(), loadViewsFrom() and similar helpers, exactly as Laravel does.
     * Callbacks must use only $this (the provider) and captured variables.
     *
     * @param-closure-this ServiceProvider $callback
     */
    private function onProviderIf(bool $condition, Closure $callback): self
    {
        if ($condition) {
            $callback->call($this->provider);
        }

        return $this;
    }
}
