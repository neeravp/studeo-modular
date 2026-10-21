<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Studeo\Modular\DataObjects\InstallResult;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\ModuleManifest;
use Studeo\Modular\Support\ResolveModuleManifestAction;
use Studeo\Modular\Support\RootComposer;

final class InstallModuleAction
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly ResolveModuleManifestAction $manifestResolver,
        private readonly EnsureProviderUsesRegistrarAction $registrarCheck,
        private readonly RootComposer $rootComposer,
        private readonly RegisterModuleNamespacesAction $namespaces = new RegisterModuleNamespacesAction,
    ) {}

    public function execute(ModuleDefinition ...$modules): InstallResult
    {
        $validated = $this->validateModules($modules);
        $rootComposer = $this->rootComposer->read();

        [$alreadyInstalled, $pending] = $this->resolvePendingModules($rootComposer, $validated);

        if (empty($pending)) {
            return new InstallResult(
                installed: [],
                alreadyInstalled: $alreadyInstalled,
                pending: [],
            );
        }

        $this->rootComposer->modify(function (array $composer) use ($pending): array {
            return $this->namespaces->execute($composer, $pending);
        });

        return new InstallResult(
            installed: array_keys($pending),
            alreadyInstalled: $alreadyInstalled,
            pending: [],
        );
    }

    public function isRootComposerUpdated(): bool
    {
        return $this->rootComposer->isUpdated();
    }

    /**
     * @param  array<ModuleDefinition>  $modules
     * @return array<array{ModuleDefinition, ModuleManifest}>
     */
    private function validateModules(array $modules): array
    {
        $validated = [];

        foreach ($modules as $module) {
            $this->ensureModuleExists($module);
            $manifest = $this->manifestResolver->execute($module);
            $this->ensureServiceProviderExists($module);
            $this->registrarCheck->execute($manifest, $this->providerFile($module));

            $validated[] = [$module, $manifest];
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $rootComposer
     * @param  array<array{ModuleDefinition, ModuleManifest}>  $validated
     * @return array{list<string>, array<string, array{ModuleDefinition, ModuleManifest}>}
     */
    private function resolvePendingModules(array $rootComposer, array $validated): array
    {
        return [
            $this->resolveAlreadyInstalled($rootComposer, $validated),
            $this->resolvePendingList($rootComposer, $validated),
        ];
    }

    /**
     * @param  array<string, mixed>  $rootComposer
     * @param  array<array{ModuleDefinition, ModuleManifest}>  $validated
     * @return list<string>
     */
    private function resolveAlreadyInstalled(array $rootComposer, array $validated): array
    {
        $installed = array_filter(
            $validated,
            fn (array $pair): bool => $this->isAlreadyInstalled($rootComposer, $pair[1]),
        );

        return array_values(array_map(fn (array $pair): string => $pair[0]->name, $installed));
    }

    /**
     * @param  array<string, mixed>  $rootComposer
     * @param  array<array{ModuleDefinition, ModuleManifest}>  $validated
     * @return array<string, array{ModuleDefinition, ModuleManifest}>
     */
    private function resolvePendingList(array $rootComposer, array $validated): array
    {
        $pending = array_filter(
            $validated,
            fn (array $pair): bool => ! $this->isAlreadyInstalled($rootComposer, $pair[1]),
        );

        $result = [];

        foreach ($pending as $pair) {
            $result[$pair[0]->name] = $pair;
        }

        return $result;
    }

    private function ensureModuleExists(ModuleDefinition $module): void
    {
        if (! $this->files->isDirectory($module->path)) {
            throw new RuntimeException("Module [{$module->name}] not found at [{$module->path}].");
        }
    }

    private function providerFile(ModuleDefinition $module): string
    {
        return $module->path.DIRECTORY_SEPARATOR.$module->name.'ServiceProvider.php';
    }

    private function ensureServiceProviderExists(ModuleDefinition $module): void
    {
        $providerFile = $this->providerFile($module);

        if (! $this->files->isFile($providerFile)) {
            throw new RuntimeException("Module service provider file not found: {$providerFile}");
        }

        $this->ensureServiceProviderExtendsBase($providerFile, $module);
    }

    private function ensureServiceProviderExtendsBase(string $providerFile, ModuleDefinition $module): void
    {
        $contents = $this->files->get($providerFile);
        $className = $module->name.'ServiceProvider';

        if (! $this->isBaseServiceProvider($contents, $className)) {
            throw new RuntimeException(
                "Module service provider [{$className}] must extend Illuminate\\Support\\ServiceProvider: {$providerFile}"
            );
        }
    }

    private function isBaseServiceProvider(string $contents, string $className): bool
    {
        return $this->importsBaseServiceProvider($contents)
            && $this->extendsBaseServiceProvider($contents, $className);
    }

    private function importsBaseServiceProvider(string $contents): bool
    {
        return str_contains($contents, 'use Illuminate\Support\ServiceProvider;')
            || str_contains($contents, 'use Illuminate\Support\ServiceProvider as ')
            || str_contains($contents, '\Illuminate\Support\ServiceProvider');
    }

    private function extendsBaseServiceProvider(string $contents, string $className): bool
    {
        $pattern = '/class\s+'.preg_quote($className, '/').'\s+extends\s+(?:\\\\Illuminate\\\\Support\\\\)?ServiceProvider\b/i';

        return (bool) preg_match($pattern, $contents);
    }

    /**
     * @param  array<string, mixed>  $rootComposer
     */
    private function isAlreadyInstalled(
        array $rootComposer,
        ModuleManifest $manifest,
    ): bool {
        return isset($rootComposer['autoload']['psr-4'][$manifest->namespace.'\\']);
    }
}
