<?php

namespace Studeo\Modular\Console\Actions;

use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Support\RootComposer;

final class UninstallModuleAction
{
    public function __construct(
        private readonly RootComposer $rootComposer,
        private readonly ResolveInstalledNamespaceAction $installedNamespace,
    ) {}

    public function execute(ModuleDefinition $module): bool
    {
        $namespace = $this->installedNamespace->execute($module);

        if ($namespace === null) {
            return false;
        }

        return $this->unregister($namespace);
    }

    private function unregister(string $namespace): bool
    {
        return $this->rootComposer->modify(function (array $composer) use ($namespace): array {
            return $this->removeModuleNamespaces($composer, $namespace);
        });
    }

    /**
     * @param  array<string, mixed>  $composer
     * @return array<string, mixed>
     */
    private function removeModuleNamespaces(array $composer, string $namespace): array
    {
        if (! $this->hasPsr4Autoload($composer)) {
            return $composer;
        }

        $composer['autoload']['psr-4'] = $this->removeMatchingNamespaces(
            $composer['autoload']['psr-4'],
            $namespace,
        );

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
     * @param  array<string, mixed>  $psr4
     * @return array<string, mixed>
     */
    private function removeMatchingNamespaces(array $psr4, string $namespace): array
    {
        return array_filter(
            $psr4,
            fn (string $key): bool => ! $this->matchesNamespace($key, $namespace),
            ARRAY_FILTER_USE_KEY,
        );
    }

    private function matchesNamespace(string $key, string $namespace): bool
    {
        return $key === $namespace || str_starts_with($key, $namespace);
    }
}
