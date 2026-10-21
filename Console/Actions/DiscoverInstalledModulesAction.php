<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use RuntimeException;
use Studeo\Modular\DataObjects\DiscoveredInstalledModules;
use Studeo\Modular\DataObjects\InstalledModule;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\ModuleManifest;
use Studeo\Modular\Exceptions\InvalidModuleManifestException;
use Studeo\Modular\Exceptions\LegacyModuleProviderException;
use Studeo\Modular\Exceptions\ModuleLoadException;
use Studeo\Modular\Support\ResolveModuleManifestAction;
use Studeo\Modular\Support\RootComposer;

final class DiscoverInstalledModulesAction
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly RootComposer $rootComposer,
        private readonly ResolveModuleAction $resolver,
        private readonly ResolveModuleManifestAction $manifestResolver,
        private readonly EnsureProviderUsesRegistrarAction $registrarCheck,
    ) {}

    /**
     * The installed modules that loaded, ignoring failures.
     *
     * @return array<string, InstalledModule>
     */
    public function execute(): array
    {
        return $this->discover()->installed;
    }

    /**
     * Every module mapped in the root composer.json, split into those that loaded
     * and those that failed. Failures are returned, never swallowed.
     */
    public function discover(): DiscoveredInstalledModules
    {
        if (! $this->files->isFile($this->rootComposer->path())) {
            return new DiscoveredInstalledModules;
        }

        try {
            $names = $this->rootComposer->installedModules();
        } catch (RuntimeException $exception) {
            return new DiscoveredInstalledModules(failures: ['composer.json' => ModuleLoadException::rootComposerUnreadable($exception)]);
        }

        return $this->loadModules($names);
    }

    /**
     * @param  array<int, string>  $names
     */
    private function loadModules(array $names): DiscoveredInstalledModules
    {
        $results = array_map($this->attempt(...), $names);
        $loaded = array_filter($results, fn (InstalledModule|ModuleLoadException $result): bool => $result instanceof InstalledModule);
        /** @var array<int, ModuleLoadException> $failed */
        $failed = array_diff_key($results, $loaded);

        return new DiscoveredInstalledModules(
            array_combine(array_map(fn (InstalledModule $installed): string => $installed->module->name, $loaded), $loaded),
            array_combine(array_map(fn (int|string $position): string => $names[$position], array_keys($failed)), $failed),
        );
    }

    private function attempt(string $name): InstalledModule|ModuleLoadException
    {
        try {
            return $this->loadModule($name);
        } catch (ModuleLoadException $exception) {
            return $exception;
        }
    }

    private function loadModule(string $name): InstalledModule
    {
        $module = $this->resolveModule($name);

        $this->ensureDirectoryExists($module);
        $manifest = $this->resolveManifest($module);
        $providerFile = $this->resolveProviderFile($module);

        $this->ensureProviderUsesRegistrar($module, $manifest, $providerFile);

        return new InstalledModule($module, $manifest, $providerFile);
    }

    /**
     * Hard cut: a provider that does not use ModuleRegistrar registers nothing, so the
     * module fails to load and the message prints the provider to paste in.
     */
    private function ensureProviderUsesRegistrar(ModuleDefinition $module, ModuleManifest $manifest, string $providerFile): void
    {
        try {
            $this->registrarCheck->execute($manifest, $providerFile);
        } catch (LegacyModuleProviderException $exception) {
            throw ModuleLoadException::legacyProvider($module->name, $exception);
        }
    }

    private function resolveModule(string $name): ModuleDefinition
    {
        try {
            return $this->resolver->execute($name);
        } catch (InvalidArgumentException $exception) {
            throw ModuleLoadException::invalidName($name, $exception);
        } catch (InvalidModuleManifestException $exception) {
            throw ModuleLoadException::invalidManifest($name, $exception);
        }
    }

    private function ensureDirectoryExists(ModuleDefinition $module): void
    {
        if (! $this->files->isDirectory($module->path)) {
            throw ModuleLoadException::directoryMissing($module->name, $module->path);
        }
    }

    private function resolveManifest(ModuleDefinition $module): ModuleManifest
    {
        try {
            return $this->manifestResolver->execute($module);
        } catch (InvalidModuleManifestException $exception) {
            throw ModuleLoadException::invalidManifest($module->name, $exception);
        }
    }

    private function resolveProviderFile(ModuleDefinition $module): string
    {
        $providerFile = $module->path.DIRECTORY_SEPARATOR.$module->name.'ServiceProvider.php';

        return $this->files->isFile($providerFile)
            ? $providerFile
            : throw ModuleLoadException::providerMissing($module->name, $providerFile);
    }
}
