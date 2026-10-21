<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Studeo\Modular\Console\Generation\DirectoryPruner;
use Studeo\Modular\Console\Generation\ModuleGuard;
use Studeo\Modular\Console\Generation\NameSanitizer;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\VueComponentDefinition;
use Studeo\Modular\DataObjects\VueDeletionResult;

final class DeleteVueComponentAction
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly DiscoverVueComponentsAction $discoverer,
        private readonly NameSanitizer $sanitizer,
        private readonly ModuleGuard $moduleGuard,
        private readonly DirectoryPruner $directoryPruner,
    ) {}

    public function execute(
        ModuleDefinition $module,
        string $name,
        ?bool $isPage = null,
    ): VueDeletionResult {
        $this->moduleGuard->ensureExists($module);

        $component = $this->resolveComponent($module, $name, $isPage);
        $this->deleteComponentFile($component);

        return new VueDeletionResult($component, $component->path);
    }

    private function resolveComponent(
        ModuleDefinition $module,
        string $name,
        ?bool $isPage,
    ): VueComponentDefinition {
        $cleanName = $this->sanitizer->classPath($name, 'component');
        $component = $this->discoverer->find($module, $cleanName, $isPage);

        if ($component === null) {
            $type = $this->resolveComponentTypeLabel($isPage);

            throw new RuntimeException("{$type} [{$cleanName}] does not exist in module [{$module->name}].");
        }

        return $component;
    }

    private function deleteComponentFile(VueComponentDefinition $component): void
    {
        if (! $this->files->isFile($component->path)) {
            return;
        }

        $this->files->delete($component->path);
        $this->directoryPruner->pruneEmptyParentDirectories($component->path, $this->resolveStopDirectory($component));
    }

    private function resolveStopDirectory(VueComponentDefinition $component): string
    {
        $subfolder = $component->isPage() ? 'Pages' : 'Components';

        return $component->module->path.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'js'.DIRECTORY_SEPARATOR.$subfolder;
    }

    private function resolveComponentTypeLabel(?bool $isPage): string
    {
        return match ($isPage) {
            true => 'Vue Page',
            false => 'Vue Component',
            null => 'Vue component',
        };
    }
}
