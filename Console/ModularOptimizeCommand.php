<?php

namespace Studeo\Modular\Console;

use Illuminate\Console\Command;
use RuntimeException;
use Studeo\Modular\Console\Actions\BuildModuleCacheAction;
use Studeo\Modular\Exceptions\ModuleLoadException;
use Studeo\Modular\Support\ModuleCache;
use Throwable;

/**
 * What `optimize` runs for the module cache. `optimize` hides the output and ignores the exit
 * code of every command it runs, so a failure is only seen when it is thrown.
 */
class ModularOptimizeCommand extends Command
{
    protected $signature = 'modular:optimize';

    protected $description = 'Build the module cache for optimize, throwing the failure so that optimize shows it.';

    protected $hidden = true;

    public function handle(BuildModuleCacheAction $builder, ModuleCache $cache): int
    {
        $build = $builder->execute();

        if ($build->failures !== []) {
            throw new RuntimeException($this->refusalMessage($build->failures));
        }

        $cache->write($build->modules);

        return self::SUCCESS;
    }

    /**
     * @param  array<int, ModuleLoadException>  $failures
     */
    private function refusalMessage(array $failures): string
    {
        $messages = array_map(fn (Throwable $failure): string => $failure->getMessage(), $failures);

        return implode(PHP_EOL, [...$messages, 'Modules failed to load, so nothing was cached. Fix them and run [modular:cache] again.']);
    }
}
