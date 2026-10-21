<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;
use Studeo\Modular\Console\Generation\StubRenderer;
use Studeo\Modular\DataObjects\LivewireResolvedLayout;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\ModuleManifest;

final class EnsureLivewireLayoutExistsAction
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly ResolveModuleAction $moduleResolver,
        private readonly StubRenderer $stubRenderer,
    ) {}

    public function execute(ModuleDefinition $module, ?string $rawLayout): LivewireResolvedLayout
    {
        $resolved = $this->resolveLayout($module, $rawLayout);

        if ($this->files->exists($resolved->filePath)) {
            return $resolved;
        }

        $this->scaffoldLayoutFile($resolved->filePath, $resolved->layoutName);

        return $resolved->withCreated(true);
    }

    public function resolveLayout(ModuleDefinition $currentModule, ?string $rawLayout): LivewireResolvedLayout
    {
        $clean = trim((string) $rawLayout);

        return match (true) {
            $clean === '' => $this->buildResolvedLayout($currentModule, 'app'),
            str_contains($clean, '::') => $this->resolveNamespacedLayout($clean),
            str_contains($clean, '.') => $this->resolveDotNotatedLayout($currentModule, $clean),
            default => $this->buildResolvedLayout($currentModule, $clean),
        };
    }

    private function resolveNamespacedLayout(string $layout): LivewireResolvedLayout
    {
        [$moduleName, $viewName] = explode('::', $layout, 2);
        $targetModule = $this->resolveRequiredModule($moduleName);
        $layoutName = Str::startsWith($viewName, 'layouts.')
            ? Str::after($viewName, 'layouts.')
            : $viewName;

        return $this->buildResolvedLayout($targetModule, $layoutName);
    }

    private function resolveDotNotatedLayout(ModuleDefinition $currentModule, string $layout): LivewireResolvedLayout
    {
        $segments = explode('.', $layout);
        $first = $segments[0];

        if ($this->isModuleReference($first, $currentModule->name)) {
            return $this->buildResolvedLayout($currentModule, $this->extractLayoutNameFromSegments($segments, offset: 1));
        }

        if ($candidateModule = $this->findExistingModule($first)) {
            return $this->buildResolvedLayout($candidateModule, $this->extractLayoutNameFromSegments($segments, offset: 1));
        }

        $offset = $first === 'layouts' ? 1 : 0;

        return $this->buildResolvedLayout($currentModule, $this->extractLayoutNameFromSegments($segments, offset: $offset));
    }

    /**
     * @param  array<string>  $segments
     */
    private function extractLayoutNameFromSegments(array $segments, int $offset): string
    {
        $sliced = array_slice($segments, $offset);

        if (($sliced[0] ?? null) === 'layouts') {
            $sliced = array_slice($sliced, 1);
        }

        return implode('.', $sliced);
    }

    private function buildResolvedLayout(ModuleDefinition $module, string $layoutName): LivewireResolvedLayout
    {
        $cleanName = $layoutName !== '' ? $layoutName : 'app';
        $moduleKey = ModuleManifest::keyFor($module->name);
        $viewAttribute = "{$moduleKey}::layouts.{$cleanName}";
        $relativeFile = str_replace('.', DIRECTORY_SEPARATOR, $cleanName).'.blade.php';
        $filePath = $module->path.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'views'.DIRECTORY_SEPARATOR.'layouts'.DIRECTORY_SEPARATOR.$relativeFile;

        return new LivewireResolvedLayout($module, $cleanName, $viewAttribute, $filePath);
    }

    private function scaffoldLayoutFile(string $filePath, string $layoutName): void
    {
        $this->files->ensureDirectoryExists(dirname($filePath));

        $stub = $this->files->get($this->stubRenderer->path('livewire.layout.blade.php.stub'));
        $title = Str::headline(basename($layoutName));
        $content = str_replace('{{ title }}', $title, $stub);

        $this->files->put($filePath, $content);
    }

    private function resolveRequiredModule(string $name): ModuleDefinition
    {
        $found = $this->findExistingModule($name);

        if ($found !== null) {
            return $found;
        }

        $module = $this->moduleResolver->execute($name);

        if (! $this->files->isDirectory($module->path)) {
            throw new RuntimeException("Module directory does not exist [{$module->path}].");
        }

        return $module;
    }

    /**
     * A layout reference names its module by the kebab key (what generated code
     * contains) or, as before, by the module name in any case.
     */
    private function isModuleReference(string $reference, string $moduleName): bool
    {
        return strcasecmp($moduleName, $reference) === 0 || ModuleManifest::keyFor($moduleName) === $reference;
    }

    private function findExistingModule(string $name): ?ModuleDefinition
    {
        $rootPath = (string) config('modular.root_path');

        if (! $this->files->isDirectory($rootPath)) {
            return null;
        }

        $names = array_map('basename', $this->files->directories($rootPath));
        $matching = array_filter($names, fn (string $realName): bool => $this->isModuleReference($name, $realName));
        $first = array_shift($matching);

        return $first === null ? null : $this->moduleResolver->execute($first);
    }
}
