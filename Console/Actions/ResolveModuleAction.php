<?php

namespace Studeo\Modular\Console\Actions;

use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Exceptions\InvalidModuleManifestException;
use Studeo\Modular\Support\ModuleNamespace;
use Studeo\Modular\Support\ResolveModuleManifestAction;

/**
 * Turns a module name into a ModuleDefinition. An existing module takes its namespace from its
 * manifest and never from configuration; only a module being made (or one with no manifest yet)
 * takes it from the root namespace.
 */
final class ResolveModuleAction
{
    public function __construct(
        private readonly ResolveModuleManifestAction $manifestResolver,
    ) {}

    /**
     * @throws \InvalidArgumentException for a name or configured root namespace that breaks the rules
     * @throws InvalidModuleManifestException for a manifest that exists and cannot be used
     */
    public function execute(string $module): ModuleDefinition
    {
        $name = $this->sanitize($module);
        $path = $this->resolvePath($name);

        return new ModuleDefinition(
            name: $name,
            namespace: $this->existingNamespace($path) ?? $this->resolveNamespace($name, config('modular.root_namespace')),
            path: $path,
        );
    }

    /**
     * A module that does not exist yet: the namespace is the given root namespace, else the
     * configured one, else none. An empty string means no prefix.
     */
    public function forNewModule(string $module, ?string $rootNamespace = null): ModuleDefinition
    {
        $name = $this->sanitize($module);

        return new ModuleDefinition(
            name: $name,
            namespace: $this->resolveNamespace($name, $rootNamespace ?? config('modular.root_namespace')),
            path: $this->resolvePath($name),
        );
    }

    /**
     * For uninstall and remove, which find a module by its root composer.json entry and its
     * directory: an unusable manifest must not stop them from repairing it, and they never read
     * the namespace, so a module with no usable manifest carries its name in its place.
     */
    public function forRepair(string $module): ModuleDefinition
    {
        $name = $this->sanitize($module);
        $path = $this->resolvePath($name);

        try {
            $namespace = $this->existingNamespace($path) ?? $name;
        } catch (InvalidModuleManifestException) {
            $namespace = $name;
        }

        return new ModuleDefinition(name: $name, namespace: $namespace, path: $path);
    }

    private function existingNamespace(string $path): ?string
    {
        return $this->manifestResolver->hasManifest($path)
            ? $this->manifestResolver->forDirectory($path)->namespace
            : null;
    }

    private function resolveNamespace(string $name, mixed $rootNamespace): string
    {
        return ModuleNamespace::for($name, $rootNamespace === null ? null : (string) $rootNamespace);
    }

    private function resolvePath(string $name): string
    {
        return rtrim(config('modular.root_path'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$name;
    }

    private function sanitize(string $module): string
    {
        ModuleNamespace::assertName($module);

        return $module;
    }
}
