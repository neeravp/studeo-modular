<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\ModuleManifest;

final class CreateModuleAction
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly RenderModuleProviderAction $providerRenderer,
    ) {}

    public function execute(ModuleDefinition $module): void
    {
        $this->ensureModuleDoesNotExist($module);

        $this->createDirectories($module);
        $this->createFiles($module);
    }

    private function ensureModuleDoesNotExist(ModuleDefinition $module): void
    {
        if ($this->files->isDirectory($module->path)) {
            throw new RuntimeException(
                "Module [{$module->name}] already exists."
            );
        }
    }

    private function createDirectories(ModuleDefinition $module): void
    {
        foreach ($this->directories() as $directory) {
            $this->files->ensureDirectoryExists(
                $module->path.DIRECTORY_SEPARATOR.$directory
            );
        }
    }

    private function createFiles(ModuleDefinition $module): void
    {
        $this->put($module, $module->name.'ServiceProvider.php', $this->serviceProvider($module));
        $this->put($module, ModuleManifest::FILE, (new ModuleManifest($module->name, $module->namespace))->toJson());
        $this->put($module, 'config/'.$this->configFileName($module), $this->config());
        $this->put($module, 'resources/css/module.css', '');
        $this->put($module, 'resources/js/module.js', '');
        $this->put($module, 'routes/web.php', $this->routes());
    }

    private function put(ModuleDefinition $module, string $path, string $contents): void
    {
        $this->files->put(
            $module->path.DIRECTORY_SEPARATOR.$path,
            $contents
        );
    }

    /**
     * @return list<string>
     */
    private function directories(): array
    {
        return [
            'Actions',
            'ApiResources',
            'Commands',
            'Contracts',
            'Controllers',
            'Data',
            'Middleware',
            'Models',
            'Policies',
            'database/factories',
            'database/migrations',
            'database/seeders',
            'resources/css',
            'resources/js',
            'resources/views',
            'routes',
            'config',
        ];
    }

    private function serviceProvider(ModuleDefinition $module): string
    {
        return $this->providerRenderer->execute(new ModuleManifest($module->name, $module->namespace));
    }

    private function config(): string
    {
        return $this->files->get(__DIR__.'/../stubs/config.php.stub');
    }

    private function routes(): string
    {
        return $this->files->get(__DIR__.'/../stubs/web.php.stub');
    }

    private function configKey(ModuleDefinition $module): string
    {
        return ModuleManifest::keyFor($module->name);
    }

    private function configFileName(ModuleDefinition $module): string
    {
        return $this->configKey($module).'.php';
    }
}
