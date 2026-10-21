<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Studeo\Modular\DataObjects\ModuleDefinition;

final class RemoveModuleAction
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly ResolveInstalledNamespaceAction $installedNamespace,
    ) {}

    public function execute(ModuleDefinition $module): void
    {
        $this->ensureModuleIsNotInstalled($module);
        $this->ensureModuleExistsOnDisk($module);

        $this->files->deleteDirectory($module->path);
    }

    private function ensureModuleIsNotInstalled(ModuleDefinition $module): void
    {
        if ($this->installedNamespace->execute($module) !== null) {
            throw new RuntimeException(
                "Cannot remove module [{$module->name}] because it is currently installed. Run [php artisan module:uninstall {$module->name}] first."
            );
        }
    }

    private function ensureModuleExistsOnDisk(ModuleDefinition $module): void
    {
        if (! $this->files->isDirectory($module->path)) {
            throw new RuntimeException(
                "Module [{$module->name}] not found at [{$module->path}]."
            );
        }
    }
}
