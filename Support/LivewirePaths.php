<?php

namespace Studeo\Modular\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\ModuleManifest;

/**
 * Where a module keeps each shape of Livewire component, and the names that address it.
 */
final class LivewirePaths
{
    public function __construct(
        private readonly Filesystem $files,
    ) {}

    /**
     * Convert component name or path to kebab alias e.g. "Admin/UserTable" -> "admin.user-table".
     */
    public function formatKebabPath(string $path): string
    {
        $normalized = str_replace(['\\', '.'], '/', $path);

        return collect(explode('/', $normalized))
            ->filter(fn (string $segment): bool => $segment !== '')
            ->map(fn (string $segment): string => Str::kebab($segment))
            ->implode('.');
    }

    public function classesLivewirePath(ModuleDefinition $module): string
    {
        return $module->path.DIRECTORY_SEPARATOR.'Livewire';
    }

    public function viewsLivewirePath(ModuleDefinition $module): string
    {
        return $module->path.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'views'.DIRECTORY_SEPARATOR.'livewire';
    }

    public function testsLivewirePath(ModuleDefinition $module): string
    {
        return $module->path.DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Feature'.DIRECTORY_SEPARATOR.'Livewire';
    }

    public function resolveFeatureTestPath(ModuleDefinition $module, string $componentName): string
    {
        $relative = str_replace('/', DIRECTORY_SEPARATOR, $componentName).'Test.php';

        return $this->testsLivewirePath($module).DIRECTORY_SEPARATOR.$relative;
    }

    /**
     * The test file a generator wrote for the component, found by the one spelling generators use.
     */
    public function resolveExistingFeatureTestPath(ModuleDefinition $module, string $name): ?string
    {
        $path = $this->resolveFeatureTestPath($module, $this->studlyPath($name));

        if (! $this->files->isFile($path)) {
            return null;
        }

        return $this->canonicalPath($path);
    }

    private function studlyPath(string $name): string
    {
        return collect(explode('/', $name))->map(fn (string $segment): string => Str::studly($segment))->implode('/');
    }

    private function canonicalPath(string $path): string
    {
        return realpath($path) ?: $path;
    }

    public function resolveClassicClassPath(ModuleDefinition $module, string $componentName): string
    {
        return $this->classesLivewirePath($module).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $componentName).'.php';
    }

    public function resolveClassicViewPath(ModuleDefinition $module, string $componentName): string
    {
        $alias = $this->formatKebabPath($componentName);

        return $this->viewsLivewirePath($module).DIRECTORY_SEPARATOR.str_replace('.', DIRECTORY_SEPARATOR, $alias).'.blade.php';
    }

    /**
     * A multi-file component is the directory ⚡{name}, holding {name}.php, {name}.blade.php and
     * the files that go with them, as Livewire 4's own make command lays it out.
     */
    public function resolveMfcDirectory(ModuleDefinition $module, string $componentName): string
    {
        return $this->zapPath($module, $componentName, '');
    }

    /**
     * The name every file inside a multi-file component directory starts with.
     */
    public function mfcFileBase(string $componentName): string
    {
        return Str::kebab(basename($componentName));
    }

    public function resolveSfcPath(ModuleDefinition $module, string $componentName): string
    {
        return $this->zapPath($module, $componentName, '.blade.php');
    }

    /**
     * The name that addresses a component, as generated code writes it: {key}.{alias}.
     */
    public function livewireAlias(ModuleDefinition $module, string $componentName): string
    {
        return ModuleManifest::keyFor($module->name).'.'.$this->formatKebabPath($componentName);
    }

    public function buildClassicNamespace(ModuleDefinition $module, string $componentName): string
    {
        $dir = dirname($componentName);

        if ($this->isTopLevel($dir)) {
            return $module->namespace.'\\Livewire';
        }

        return $module->namespace.'\\Livewire\\'.str_replace('/', '\\', $dir);
    }

    public function buildClassicFqcn(ModuleDefinition $module, string $componentName): string
    {
        return $this->buildClassicNamespace($module, $componentName).'\\'.basename($componentName);
    }

    private function zapPath(ModuleDefinition $module, string $componentName, string $suffix): string
    {
        $targetDir = $this->classesDirectoryOf($module, $componentName);

        return $targetDir.DIRECTORY_SEPARATOR.'⚡'.Str::kebab(basename($componentName)).$suffix;
    }

    private function classesDirectoryOf(ModuleDefinition $module, string $componentName): string
    {
        $dirName = dirname($componentName);

        if ($this->isTopLevel($dirName)) {
            return $this->classesLivewirePath($module);
        }

        return $this->classesLivewirePath($module).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $dirName);
    }

    private function isTopLevel(string $dirName): bool
    {
        return $dirName === '.' || $dirName === '';
    }
}
