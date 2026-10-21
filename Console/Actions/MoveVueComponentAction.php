<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Studeo\Modular\Console\Generation\ArtifactWriter;
use Studeo\Modular\Console\Generation\DirectoryPruner;
use Studeo\Modular\Console\Generation\ModuleGuard;
use Studeo\Modular\Console\Generation\NameSanitizer;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\VueComponentDefinition;
use Studeo\Modular\DataObjects\VueMoveResult;

final class MoveVueComponentAction
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly DiscoverVueComponentsAction $discoverer,
        private readonly NameSanitizer $sanitizer,
        private readonly ModuleGuard $moduleGuard,
        private readonly ArtifactWriter $artifactWriter,
        private readonly DirectoryPruner $directoryPruner,
    ) {}

    public function execute(
        ModuleDefinition $sourceModule,
        string $sourceName,
        ModuleDefinition $targetModule,
        string $targetName,
        ?bool $targetIsPage = null,
    ): VueMoveResult {
        $this->validateModules($sourceModule, $targetModule);

        $source = $this->resolveSourceComponent($sourceModule, $sourceName, $targetIsPage);
        $target = $this->resolveTargetComponent($targetModule, $targetName, $source, $targetIsPage);

        $this->ensureMoveIsValid($source, $target);
        $this->relocateComponent($source, $target);

        return new VueMoveResult($source, $target, $source->path, $target->path);
    }

    private function validateModules(ModuleDefinition ...$modules): void
    {
        foreach ($modules as $module) {
            $this->moduleGuard->ensureExists($module);
        }
    }

    private function resolveSourceComponent(
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

    private function resolveTargetComponent(
        ModuleDefinition $module,
        string $name,
        VueComponentDefinition $source,
        ?bool $targetIsPage,
    ): VueComponentDefinition {
        $cleanName = $this->sanitizer->classPath($name, 'component');
        $isPage = $targetIsPage ?? $source->isPage();
        $subfolder = $isPage ? 'Pages' : 'Components';
        $path = $module->path.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'js'.DIRECTORY_SEPARATOR.$subfolder.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $cleanName).'.vue';

        return new VueComponentDefinition(
            module: $module,
            name: $cleanName,
            type: $isPage ? 'page' : 'component',
            path: $path,
        );
    }

    private function ensureMoveIsValid(VueComponentDefinition $source, VueComponentDefinition $target): void
    {
        $this->ensureMoveIsNotNoop($source, $target);
        $this->ensureTargetIsFree($target);
    }

    private function ensureMoveIsNotNoop(VueComponentDefinition $source, VueComponentDefinition $target): void
    {
        if ($this->isSameComponent($source, $target)) {
            throw new RuntimeException("Cannot move component [{$source->name}] to the same name in module [{$source->module->name}].");
        }
    }

    private function isSameComponent(VueComponentDefinition $source, VueComponentDefinition $target): bool
    {
        return $source->module->name === $target->module->name && $source->name === $target->name && $source->type === $target->type;
    }

    private function ensureTargetIsFree(VueComponentDefinition $target): void
    {
        $this->artifactWriter->ensureDoesNotExist(
            $target->path,
            $target->displayType(),
            $target->name,
            $target->module
        );
    }

    private function relocateComponent(VueComponentDefinition $source, VueComponentDefinition $target): void
    {
        $this->files->ensureDirectoryExists(dirname($target->path));
        $this->files->move($source->path, $target->path);

        $stopAt = $source->isPage()
            ? $source->module->path.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'js'.DIRECTORY_SEPARATOR.'Pages'
            : $source->module->path.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'js'.DIRECTORY_SEPARATOR.'Components';

        $this->directoryPruner->pruneEmptyParentDirectories($source->path, $stopAt);
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
