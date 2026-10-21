<?php

namespace Studeo\Modular\Support;

use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use JsonException;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\ModuleManifest;
use Studeo\Modular\Exceptions\InvalidModuleManifestException;

final class ResolveModuleManifestAction
{
    public function __construct(
        private readonly Filesystem $files,
    ) {}

    public function execute(ModuleDefinition $module): ModuleManifest
    {
        $this->ensureManifestExists($module, $this->manifestPath($module));

        return $this->forDirectory($module->path);
    }

    /**
     * Read the manifest of the module living in the given directory, without
     * consulting configuration. Used by the registrar at runtime.
     */
    public function forDirectory(string $directory): ModuleManifest
    {
        $path = $directory.DIRECTORY_SEPARATOR.ModuleManifest::FILE;

        if (! $this->files->isFile($path)) {
            throw InvalidModuleManifestException::missing($path);
        }

        $data = $this->readManifest($path);

        return new ModuleManifest(
            name: $this->resolveName($data, basename($directory), $path),
            namespace: $this->resolveNamespace($data, $path),
        );
    }

    public function hasManifest(string $directory): bool
    {
        return $this->files->isFile($directory.DIRECTORY_SEPARATOR.ModuleManifest::FILE);
    }

    private function manifestPath(ModuleDefinition $module): string
    {
        return $module->path.DIRECTORY_SEPARATOR.ModuleManifest::FILE;
    }

    private function ensureManifestExists(ModuleDefinition $module, string $path): void
    {
        match (true) {
            $this->files->isFile($path) => null,
            $this->hasLegacyManifest($module) => throw InvalidModuleManifestException::legacy(
                $path,
                new ModuleManifest($module->name, $module->namespace)
            ),
            default => throw InvalidModuleManifestException::missing($path),
        };
    }

    private function hasLegacyManifest(ModuleDefinition $module): bool
    {
        return $this->files->isFile($module->path.DIRECTORY_SEPARATOR.'composer.json');
    }

    /**
     * @return array<string, mixed>
     */
    private function readManifest(string $path): array
    {
        try {
            $data = json_decode($this->files->get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw InvalidModuleManifestException::invalid($path, $exception->getMessage());
        }

        return is_array($data) ? $data : throw InvalidModuleManifestException::invalid($path, 'expected a JSON object.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveName(array $data, string $directoryName, string $path): string
    {
        $name = $data['name'] ?? null;

        return $name === $directoryName
            ? $name
            : throw InvalidModuleManifestException::invalid($path, "name must be the directory name [{$directoryName}].");
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveNamespace(array $data, string $path): string
    {
        $namespace = $data['namespace'] ?? null;

        if (! is_string($namespace)) {
            throw InvalidModuleManifestException::invalid($path, 'namespace must be a string.');
        }

        try {
            ModuleNamespace::assertValid($namespace);
        } catch (InvalidArgumentException $exception) {
            throw InvalidModuleManifestException::invalid($path, $exception->getMessage());
        }

        return $namespace;
    }
}
