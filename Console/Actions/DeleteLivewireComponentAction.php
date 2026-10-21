<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Studeo\Modular\Console\Generation\DirectoryPruner;
use Studeo\Modular\Console\Generation\ModuleGuard;
use Studeo\Modular\DataObjects\LivewireComponentDefinition;
use Studeo\Modular\DataObjects\LivewireComponentForm;
use Studeo\Modular\DataObjects\LivewireDeletionResult;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Support\LivewirePaths;

final class DeleteLivewireComponentAction
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly DiscoverLivewireComponentsAction $discoverer,
        private readonly ResolveLivewireFormAction $forms,
        private readonly ModuleGuard $moduleGuard,
        private readonly DirectoryPruner $directoryPruner,
        private readonly LivewirePaths $livewirePaths,
    ) {}

    public function execute(ModuleDefinition $module, string $name): LivewireDeletionResult
    {
        $this->moduleGuard->ensureExists($module);

        $component = $this->ensureComponentExists($module, $name);
        $form = $this->forms->execute($module, $component);

        $deletedFiles = $this->deleteComponentFiles($module, $component);
        $deletedDirectories = $this->deleteComponentDirectory($module, $component);
        [$formFiles, $notices] = $this->deleteForm($module, $form);

        return new LivewireDeletionResult($component, array_values(array_unique([...$deletedFiles, ...$deletedDirectories, ...$formFiles])), $notices);
    }

    /**
     * The Form goes with its component unless another file names it.
     *
     * @return array{0: array<int, string>, 1: array<int, string>} the deleted files and the notices
     */
    private function deleteForm(ModuleDefinition $module, ?LivewireComponentForm $form): array
    {
        if ($form === null) {
            return [[], []];
        }

        if ($form->shared()) {
            return [[], ['Kept Form ['.$form->path.'] because other files use it: '.implode(', ', $form->references).'.']];
        }

        $this->files->delete($form->path);
        $this->directoryPruner->pruneEmptyParentDirectories($form->path, $module->path.'/Livewire/Forms');

        return [[$form->path], []];
    }

    private function ensureComponentExists(ModuleDefinition $module, string $name): LivewireComponentDefinition
    {
        $component = $this->discoverer->find($module, $name);

        if ($component === null) {
            throw new RuntimeException("Livewire component [{$name}] does not exist in module [{$module->name}].");
        }

        return $component;
    }

    /**
     * @return array<string>
     */
    private function deleteComponentFiles(ModuleDefinition $module, LivewireComponentDefinition $component): array
    {
        $deletedFiles = [];

        foreach ($component->files as $file) {
            $deletedFiles = [...$deletedFiles, ...$this->deleteExistingFile($module, $file)];
        }

        return $deletedFiles;
    }

    /**
     * @return array<string> the file, when there was one to delete
     */
    private function deleteExistingFile(ModuleDefinition $module, string $file): array
    {
        if (! $this->files->isFile($file)) {
            return [];
        }

        $this->files->delete($file);
        $this->pruneEmptyParentDirectoriesForFile($module, $file);

        return [$file];
    }

    /**
     * @return array<string> the component's directory, when it is still there to delete
     */
    private function deleteComponentDirectory(ModuleDefinition $module, LivewireComponentDefinition $component): array
    {
        if (! $this->hasDirectoryLeft($component)) {
            return [];
        }

        $this->files->deleteDirectory($component->path);
        $this->directoryPruner->pruneEmptyParentDirectories($component->path, $this->livewirePaths->classesLivewirePath($module));

        return [$component->path];
    }

    private function hasDirectoryLeft(LivewireComponentDefinition $component): bool
    {
        return $component->isDirectoryBased() && $this->files->isDirectory($component->path);
    }

    private function pruneEmptyParentDirectoriesForFile(ModuleDefinition $module, string $file): void
    {
        $stopAt = $this->resolveStopDirectory($module, $file);

        if ($stopAt !== null) {
            $this->directoryPruner->pruneEmptyParentDirectories($file, $stopAt);
        }
    }

    private function resolveStopDirectory(ModuleDefinition $module, string $path): ?string
    {
        return match (true) {
            $this->isInsideViewsDirectory($module, $path) => $this->livewirePaths->viewsLivewirePath($module),
            $this->isInsideTestsDirectory($module, $path) => $this->livewirePaths->testsLivewirePath($module),
            $this->isInsideClassesDirectory($module, $path) => $this->livewirePaths->classesLivewirePath($module),
            default => null,
        };
    }

    private function isInsideViewsDirectory(ModuleDefinition $module, string $path): bool
    {
        return str_starts_with($path, $this->livewirePaths->viewsLivewirePath($module));
    }

    private function isInsideTestsDirectory(ModuleDefinition $module, string $path): bool
    {
        return str_starts_with($path, $this->livewirePaths->testsLivewirePath($module));
    }

    private function isInsideClassesDirectory(ModuleDefinition $module, string $path): bool
    {
        return str_starts_with($path, $this->livewirePaths->classesLivewirePath($module));
    }
}
