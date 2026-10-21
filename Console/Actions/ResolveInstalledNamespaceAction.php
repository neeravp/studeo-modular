<?php

namespace Studeo\Modular\Console\Actions;

use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Exceptions\InvalidModuleManifestException;
use Studeo\Modular\Support\ResolveModuleManifestAction;
use Studeo\Modular\Support\RootComposer;

/**
 * Finds the PSR-4 namespace under which a module is installed in the root composer.json.
 * Never consults modular.root_namespace, so a config change cannot orphan a module.
 */
final class ResolveInstalledNamespaceAction
{
    public function __construct(
        private readonly RootComposer $rootComposer,
        private readonly ResolveModuleManifestAction $manifestResolver,
    ) {}

    /**
     * @return string|null The installed namespace with a trailing backslash, or null when not installed.
     */
    public function execute(ModuleDefinition $module): ?string
    {
        return $this->resolveFromManifest($module) ?? $this->resolveFromRootMapping($module);
    }

    private function resolveFromManifest(ModuleDefinition $module): ?string
    {
        try {
            $namespace = $this->manifestResolver->execute($module)->namespace.'\\';
        } catch (InvalidModuleManifestException) {
            return null;
        }

        return $this->rootComposer->isInstalled($namespace) ? $namespace : null;
    }

    private function resolveFromRootMapping(ModuleDefinition $module): ?string
    {
        return $this->rootComposer->namespaceMappedTo($module->path);
    }
}
