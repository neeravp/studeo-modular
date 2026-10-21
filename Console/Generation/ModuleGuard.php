<?php

namespace Studeo\Modular\Console\Generation;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Studeo\Modular\DataObjects\ModuleDefinition;

final class ModuleGuard
{
    public function __construct(private readonly Filesystem $files) {}

    public function ensureExists(ModuleDefinition $module): void
    {
        if (! $this->files->isDirectory($module->path)) {
            throw new RuntimeException("Module directory does not exist [{$module->path}].");
        }
    }
}
