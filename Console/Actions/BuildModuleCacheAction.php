<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Router;
use Studeo\Modular\DataObjects\InstalledModule;
use Studeo\Modular\DataObjects\ModuleCacheBuild;
use Studeo\Modular\Exceptions\ModuleLoadException;
use Studeo\Modular\Exceptions\ModuleRegistrationException;
use Studeo\Modular\Support\ModuleCache;
use Studeo\Modular\Support\ModuleScanner;

/**
 * Scans every installed module live, ignoring any existing cache, and returns the
 * entries to cache. Failures come only from this scan: discovery failures plus the
 * middleware alias collisions found while building. If there are any, no entries come
 * back, because a cache must never hide a broken module. Boot-time state is never
 * consulted, since it may have been produced through a stale cache.
 */
final class BuildModuleCacheAction
{
    public function __construct(
        private readonly DiscoverInstalledModulesAction $discoverer,
        private readonly Filesystem $files,
        private readonly Router $router,
        private readonly ModuleCache $cache,
    ) {}

    public function execute(): ModuleCacheBuild
    {
        $discovered = $this->discoverer->discover();
        $modules = [];
        $failures = array_values($discovered->failures);

        foreach ($discovered->installed as $installed) {
            $scanner = new ModuleScanner($installed->module->path, $installed->manifest, $this->files);
            $middleware = $scanner->middleware();

            array_push($failures, ...$this->collisions($installed, $middleware));

            $modules[$installed->manifest->key()] = [
                'listeners' => $scanner->listeners(),
                'policies' => $scanner->policies(),
                'middleware' => $middleware,
            ];
        }

        return $failures === [] ? new ModuleCacheBuild($modules) : new ModuleCacheBuild(failures: $failures);
    }

    /**
     * An alias bound to another class is a collision, unless it is the binding this
     * module's own cached entry made at boot.
     *
     * @param  array<string, string>  $middleware
     * @return array<int, ModuleLoadException>
     */
    private function collisions(InstalledModule $installed, array $middleware): array
    {
        $bound = $this->router->getMiddleware();
        $own = $this->cache->entryFor($installed->manifest->key())['middleware'] ?? [];
        $taken = array_filter($middleware, fn (string $class, int|string $alias): bool => $this->isTaken($class, $bound[$alias] ?? $class, $own[$alias] ?? null), ARRAY_FILTER_USE_BOTH);

        return array_map(fn (string $class, int|string $alias): ModuleLoadException => $this->collision($installed, $alias, $bound[$alias], $class), $taken, array_keys($taken));
    }

    private function isTaken(string $class, mixed $existing, mixed $own): bool
    {
        return $existing !== $class && $existing !== $own;
    }

    private function collision(InstalledModule $installed, int|string $alias, string $existing, string $class): ModuleLoadException
    {
        return ModuleLoadException::registrationFailed(
            $installed->module->name,
            ModuleRegistrationException::middlewareAliasTaken($installed->module->name, (string) $alias, $existing, $class),
        );
    }
}
