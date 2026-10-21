<?php

namespace Studeo\Modular\Console\Actions;

use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\ModuleManifest;

final class RegisterModuleNamespacesAction
{
    /**
     * @param  array<string, mixed>  $composer
     * @param  array<string, array{ModuleDefinition, ModuleManifest}>  $pending
     * @return array<string, mixed>
     */
    public function execute(array $composer, array $pending): array
    {
        $composer = $this->ensurePsr4Autoload($composer);

        foreach ($pending as [$module, $manifest]) {
            $composer = $this->registerModuleNamespaces($composer, $module, $manifest);
        }

        return $composer;
    }

    /**
     * @param  array<string, mixed>  $composer
     * @return array<string, mixed>
     */
    private function ensurePsr4Autoload(array $composer): array
    {
        if (! $this->hasPsr4Autoload($composer)) {
            $composer['autoload']['psr-4'] = [];
        }

        return $composer;
    }

    /**
     * @param  array<string, mixed>  $composer
     */
    private function hasPsr4Autoload(array $composer): bool
    {
        return isset($composer['autoload']['psr-4']) && is_array($composer['autoload']['psr-4']);
    }

    /**
     * @param  array<string, mixed>  $composer
     * @return array<string, mixed>
     */
    private function registerModuleNamespaces(
        array $composer,
        ModuleDefinition $module,
        ModuleManifest $manifest,
    ): array {
        foreach ($manifest->psr4() as $namespace => $subPath) {
            $composer['autoload']['psr-4'][$namespace] = $this->resolveRelativePath($module, $subPath);
        }

        return $composer;
    }

    private function resolveRelativePath(
        ModuleDefinition $module,
        string $subPath = '',
    ): string {
        $basePath = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $modulePath = $module->path;

        if (str_starts_with($modulePath, $basePath)) {
            $relative = substr($modulePath, strlen($basePath));
        } else {
            $relative = $modulePath;
        }

        $relative = trim(str_replace('\\', '/', $relative), '/').'/';

        if ($subPath !== '') {
            $relative .= ltrim(trim(str_replace('\\', '/', $subPath), '/'), '/').'/';
        }

        return $relative;
    }
}
