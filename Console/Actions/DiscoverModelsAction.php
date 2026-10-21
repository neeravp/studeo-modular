<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Symfony\Component\Finder\SplFileInfo;

final class DiscoverModelsAction
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly DiscoverInstalledModulesAction $installedDiscoverer,
    ) {}

    /**
     * Discover models across installed modules, or within a specific module.
     *
     * @return array<string, array<string, string>>
     */
    public function execute(?ModuleDefinition $module = null): array
    {
        if ($module !== null) {
            return [$module->name => $this->forModule($module)];
        }

        $installed = $this->installedDiscoverer->execute();
        $discovered = [];

        foreach ($installed as $name => $installedModule) {
            $discovered[$name] = $this->forModule($installedModule->module);
        }

        ksort($discovered);

        return $discovered;
    }

    /**
     * Discover models for a specific module.
     *
     * @return array<string, string> Map of [ModelName => FQCN]
     */
    public function forModule(ModuleDefinition $module): array
    {
        $path = $this->modelsPath($module);

        if (! $this->files->isDirectory($path)) {
            return [];
        }

        $models = [];

        foreach ($this->phpFiles($path) as $file) {
            $modelName = $file->getFilenameWithoutExtension();
            $models[$modelName] = $module->namespace.'\\Models\\'.$modelName;
        }

        ksort($models);

        return $models;
    }

    /**
     * @return array<int, SplFileInfo>
     */
    private function phpFiles(string $path): array
    {
        return array_filter($this->files->files($path), fn (SplFileInfo $file): bool => $file->getExtension() === 'php');
    }

    private function modelsPath(ModuleDefinition $module): string
    {
        return $module->path.DIRECTORY_SEPARATOR.'Models';
    }
}
