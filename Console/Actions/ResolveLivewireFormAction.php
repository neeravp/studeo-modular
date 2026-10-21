<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use Studeo\Modular\DataObjects\LivewireComponentDefinition;
use Studeo\Modular\DataObjects\LivewireComponentForm;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Finds the Form a single-file component owns. A component has a Form only when the Form file
 * exists at the path its name derives and the component itself declares that Form, so a file that
 * merely carries the right name is never touched. The module's php files that name the Form
 * (FQCN or short name as a whole word) are its other references.
 */
final class ResolveLivewireFormAction
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly PlanLivewireFormAction $paths,
    ) {}

    public function execute(ModuleDefinition $module, LivewireComponentDefinition $component): ?LivewireComponentForm
    {
        $path = $this->paths->formPath($module, $component->name);
        $class = $this->paths->formClass($module, $component->name);

        return $this->hasForm($component, $class, $path)
            ? new LivewireComponentForm($class, $path, $this->references($module, $component, $class, $path))
            : null;
    }

    private function declares(LivewireComponentDefinition $component, string $class): bool
    {
        return $this->mentionsForm($this->files->get($component->path), $class);
    }

    private function mentionsForm(string $source, string $class): bool
    {
        return str_contains($source, "use {$class};")
            || preg_match('/public\s+'.preg_quote(class_basename($class), '/').'\s+\$form\b/', $source) === 1;
    }

    /**
     * @return array<int, string>
     */
    private function references(ModuleDefinition $module, LivewireComponentDefinition $component, string $class, string $path): array
    {
        $own = array_filter(array_map('realpath', [...$component->files, $component->path, $path]));
        $word = '/\b'.preg_quote(class_basename($class), '/').'\b/';

        return array_values(array_filter(
            array_map(fn (SplFileInfo $file): string => $file->getRealPath(), $this->files->allFiles($module->path)),
            fn (string $file): bool => $this->isOtherReference($file, $own, $word),
        ));
    }

    private function hasForm(LivewireComponentDefinition $component, string $class, string $path): bool
    {
        return $component->type === 'sfc' && $this->files->isFile($path) && $this->declares($component, $class);
    }

    /**
     * @param  array<int, string>  $own
     */
    private function isOtherReference(string $file, array $own, string $word): bool
    {
        return str_ends_with($file, '.php') && ! in_array($file, $own, true) && preg_match($word, $this->files->get($file)) === 1;
    }
}
