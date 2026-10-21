<?php

namespace Studeo\Modular\Console\Actions;

use Studeo\Modular\DataObjects\InstalledModule;
use Studeo\Modular\DataObjects\LivewireComponentDefinition;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\ModuleManifest;
use Studeo\Modular\Support\LivewireComponentScanner;
use Studeo\Modular\Support\LivewirePaths;

final class DiscoverLivewireComponentsAction
{
    public function __construct(
        private readonly LivewireComponentScanner $scanner,
        private readonly DiscoverInstalledModulesAction $modulesDiscoverer,
        private readonly LivewirePaths $livewirePaths,
    ) {}

    /**
     * @return array<string, LivewireComponentDefinition>
     */
    public function execute(): array
    {
        $installed = $this->modulesDiscoverer->execute();

        return array_replace([], ...array_values(array_map($this->componentsOf(...), $installed)));
    }

    /**
     * @return array<string, LivewireComponentDefinition>
     */
    private function componentsOf(InstalledModule $installed): array
    {
        $moduleKey = ModuleManifest::keyFor($installed->module->name);
        $components = $this->forModule($installed->module);

        return array_combine(array_map(fn (LivewireComponentDefinition $component): string => "{$moduleKey}.{$component->alias}", $components), $components);
    }

    public function find(ModuleDefinition $module, string $name): ?LivewireComponentDefinition
    {
        $searchAlias = $this->livewirePaths->formatKebabPath($name);
        $components = $this->forModule($module);

        return $components[$searchAlias] ?? null;
    }

    /**
     * @return array<string, LivewireComponentDefinition>
     */
    public function forModule(ModuleDefinition $module): array
    {
        return $this->scanner->scan($module);
    }

    /**
     * @param  array<string>  $files
     */
    public function resolveComponentDefinition(
        ModuleDefinition $module,
        string $name,
        string $target,
        string $path,
        string $type,
        array $files,
    ): LivewireComponentDefinition {
        return $this->find($module, $name)
            ?? new LivewireComponentDefinition(
                module: $module,
                name: $name,
                alias: $this->livewirePaths->formatKebabPath($name),
                target: $target,
                path: $path,
                type: $type,
                files: $files,
            );
    }
}
