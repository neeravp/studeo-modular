<?php

namespace Studeo\Modular\Console;

use Illuminate\Console\Command;
use Studeo\Modular\Console\Actions\BuildModuleCacheAction;
use Studeo\Modular\Console\Concerns\ReportsFailures;
use Studeo\Modular\Exceptions\ModuleLoadException;
use Studeo\Modular\Support\ModuleCache;
use Throwable;

class ModularCacheCommand extends Command
{
    use ReportsFailures;

    protected $signature = 'modular:cache';

    protected $description = 'Cache the listeners, policies and middleware discovered in every installed module.';

    public function handle(BuildModuleCacheAction $builder, ModuleCache $cache): int
    {
        try {
            return $this->cacheWhenEveryModuleLoads($builder, $cache);
        } catch (Throwable $exception) {
            return $this->reportFailure($exception);
        }
    }

    private function cacheWhenEveryModuleLoads(BuildModuleCacheAction $builder, ModuleCache $cache): int
    {
        $build = $builder->execute();

        if ($build->failures !== []) {
            return $this->refuseToCache($build->failures);
        }

        $cache->write($build->modules);

        $count = count($build->modules);
        $this->components->info("Cached listeners, policies and middleware for {$count} module(s).");
        $this->components->warn('Cached entries are not checked against the module files. After adding, removing or renaming a listener, policy or middleware in a cached module, run [modular:clear] (or [modular:cache] again).');

        return self::SUCCESS;
    }

    /**
     * @param  array<int, ModuleLoadException>  $failures
     */
    private function refuseToCache(array $failures): int
    {
        foreach ($failures as $failure) {
            $this->components->error($failure->getMessage());
        }

        $this->components->error('Modules failed to load, so nothing was cached. Fix them and run [modular:cache] again.');

        return self::FAILURE;
    }
}
