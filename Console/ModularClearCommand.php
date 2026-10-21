<?php

namespace Studeo\Modular\Console;

use Illuminate\Console\Command;
use Studeo\Modular\Console\Concerns\ReportsFailures;
use Studeo\Modular\Support\ModuleCache;
use Throwable;

class ModularClearCommand extends Command
{
    use ReportsFailures;

    protected $signature = 'modular:clear';

    protected $description = 'Remove the module cache file.';

    public function handle(ModuleCache $cache): int
    {
        try {
            return $this->clearCache($cache);
        } catch (Throwable $exception) {
            return $this->reportFailure($exception);
        }
    }

    private function clearCache(ModuleCache $cache): int
    {
        if ($cache->clear()) {
            $this->components->info('Module cache cleared.');
        }

        return self::SUCCESS;
    }
}
