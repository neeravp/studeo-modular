<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\ModuleManifest;
use Studeo\Modular\DataObjects\VueComponentDefinition;
use Symfony\Component\Finder\SplFileInfo;

final class DiscoverVueComponentsAction
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly DiscoverInstalledModulesAction $installedDiscoverer,
    ) {}

    /**
     * Discover Vue components across installed modules, or within a specific module.
     *
     * @return array<string, VueComponentDefinition>
     */
    public function execute(?ModuleDefinition $module = null): array
    {
        return $module !== null
            ? $this->forModule($module)
            : $this->discoverAcrossInstalledModules();
    }

    /**
     * @return array<string, VueComponentDefinition>
     */
    public function forModule(ModuleDefinition $module): array
    {
        return array_merge(
            $this->discoverPages($module),
            $this->discoverComponents($module),
        );
    }

    public function find(ModuleDefinition $module, string $name, ?bool $isPage = null): ?VueComponentDefinition
    {
        [$cleanName, $targetType] = $this->parseComponentReference($name, $isPage);

        return match ($targetType) {
            true => $this->findPage($module, $cleanName),
            false => $this->findComponent($module, $cleanName),
            null => $this->findPage($module, $cleanName) ?? $this->findComponent($module, $cleanName),
        };
    }

    /**
     * @return array<string, VueComponentDefinition>
     */
    private function discoverAcrossInstalledModules(): array
    {
        $components = [];

        foreach ($this->installedDiscoverer->execute() as $installedModule) {
            $components = array_merge(
                $components,
                $this->discoverPrefixedComponentsForModule($installedModule->module)
            );
        }

        return $components;
    }

    /**
     * @return array<string, VueComponentDefinition>
     */
    private function discoverPrefixedComponentsForModule(ModuleDefinition $module): array
    {
        $components = [];
        $moduleKey = ModuleManifest::keyFor($module->name);

        foreach ($this->forModule($module) as $key => $component) {
            $components["{$moduleKey}::{$key}"] = $component;
        }

        return $components;
    }

    /**
     * @return array<string, VueComponentDefinition>
     */
    private function discoverPages(ModuleDefinition $module): array
    {
        return $this->discoverDirectory(
            module: $module,
            directory: $this->pagesDirectory($module),
            type: 'page',
            prefix: 'Pages',
        );
    }

    /**
     * @return array<string, VueComponentDefinition>
     */
    private function discoverComponents(ModuleDefinition $module): array
    {
        return $this->discoverDirectory(
            module: $module,
            directory: $this->componentsDirectory($module),
            type: 'component',
            prefix: 'Components',
        );
    }

    /**
     * @return array<string, VueComponentDefinition>
     */
    private function discoverDirectory(
        ModuleDefinition $module,
        string $directory,
        string $type,
        string $prefix,
    ): array {
        if (! $this->files->isDirectory($directory)) {
            return [];
        }

        $components = [];

        foreach (array_filter($this->files->allFiles($directory), fn (SplFileInfo $file): bool => $file->getExtension() === 'vue') as $file) {
            $name = $this->resolveRelativeName($file);
            $components["{$prefix}/{$name}"] = new VueComponentDefinition(
                module: $module,
                name: $name,
                type: $type,
                path: $file->getRealPath() ?: $file->getPathname(),
            );
        }

        return $components;
    }

    private function findPage(ModuleDefinition $module, string $name): ?VueComponentDefinition
    {
        $path = $this->pagePath($module, $name);

        return $this->files->isFile($path)
            ? new VueComponentDefinition($module, $name, 'page', $path)
            : null;
    }

    private function findComponent(ModuleDefinition $module, string $name): ?VueComponentDefinition
    {
        $path = $this->componentPath($module, $name);

        return $this->files->isFile($path)
            ? new VueComponentDefinition($module, $name, 'component', $path)
            : null;
    }

    /**
     * @return array{0: string, 1: ?bool}
     */
    private function parseComponentReference(string $name, ?bool $isPage): array
    {
        $normalizedName = $this->normalizeReferenceName($name);

        return match (true) {
            $this->isExplicitPageReference($normalizedName) => [$this->stripPagesPrefix($normalizedName), true],
            $this->isExplicitComponentReference($normalizedName) => [$this->stripComponentsPrefix($normalizedName), false],
            default => [$normalizedName, $isPage],
        };
    }

    private function normalizeReferenceName(string $name): string
    {
        $clean = trim(str_replace('\\', '/', $name), '/');

        return (string) preg_replace('/\.vue$/i', '', $clean);
    }

    private function isExplicitPageReference(string $name): bool
    {
        return str_starts_with($name, 'Pages/');
    }

    private function isExplicitComponentReference(string $name): bool
    {
        return str_starts_with($name, 'Components/');
    }

    private function stripPagesPrefix(string $name): string
    {
        return substr($name, strlen('Pages/'));
    }

    private function stripComponentsPrefix(string $name): string
    {
        return substr($name, strlen('Components/'));
    }

    private function pagePath(ModuleDefinition $module, string $name): string
    {
        return $this->pagesDirectory($module).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $name).'.vue';
    }

    private function componentPath(ModuleDefinition $module, string $name): string
    {
        return $this->componentsDirectory($module).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $name).'.vue';
    }

    private function pagesDirectory(ModuleDefinition $module): string
    {
        return $module->path.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'js'.DIRECTORY_SEPARATOR.'Pages';
    }

    private function componentsDirectory(ModuleDefinition $module): string
    {
        return $module->path.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'js'.DIRECTORY_SEPARATOR.'Components';
    }

    private function resolveRelativeName(SplFileInfo $file): string
    {
        $relativePath = $file->getRelativePathname();

        return str_replace(['\\', '.vue'], ['/', ''], $relativePath);
    }
}
