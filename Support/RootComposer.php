<?php

namespace Studeo\Modular\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Composer;
use RuntimeException;
use Throwable;

final class RootComposer
{
    private bool $isUpdated = false;

    public function __construct(
        private readonly Filesystem $files,
        private readonly Composer $composer,
    ) {}

    public function path(): string
    {
        return base_path('composer.json');
    }

    public function isUpdated(): bool
    {
        return $this->isUpdated;
    }

    /**
     * @return array<string, mixed>
     */
    public function read(): array
    {
        $path = $this->existingPath();

        return $this->decodeRootComposer($this->files->get($path), $path);
    }

    public function isInstalled(string $namespace): bool
    {
        $rootComposer = $this->read();
        $canonicalNamespace = rtrim($namespace, '\\').'\\';

        return isset($rootComposer['autoload']['psr-4'][$canonicalNamespace]);
    }

    /**
     * The PSR-4 namespace whose path is exactly the given module directory, if any.
     */
    public function namespaceMappedTo(string $modulePath): ?string
    {
        $target = $this->relativePath($modulePath);

        $matching = array_filter($this->installedPsr4(), fn (mixed $path): bool => $this->relativePath((string) $path) === $target);

        return array_key_first($matching);
    }

    /**
     * @return array<string, string>
     */
    public function installedPsr4(): array
    {
        $rootComposer = $this->read();

        return is_array($rootComposer['autoload']['psr-4'] ?? null) ? $rootComposer['autoload']['psr-4'] : [];
    }

    /**
     * @return array<string>
     */
    public function installedModules(): array
    {
        $prefix = $this->relativePath((string) config('modular.root_path'));
        $names = array_map(fn (mixed $path): ?string => $this->moduleNameUnder((string) $path, $prefix), $this->installedPsr4());
        $unique = array_values(array_unique(array_filter($names, fn (?string $name): bool => $name !== null)));
        sort($unique);

        return $unique;
    }

    /**
     * The first directory below the modules root that a PSR-4 path points into, or null.
     */
    private function moduleNameUnder(string $path, string $prefix): ?string
    {
        $normalized = trim(str_replace('\\', '/', $path), '/').'/';
        $first = str_starts_with($normalized, $prefix) ? explode('/', trim(substr($normalized, strlen($prefix)), '/'))[0] : '';

        return empty($first) ? null : $first;
    }

    private function relativePath(string $path): string
    {
        $basePath = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $relative = str_starts_with($path, $basePath) ? substr($path, strlen($basePath)) : $path;

        return trim(str_replace('\\', '/', $relative), '/').'/';
    }

    /**
     * @param  callable(array<string, mixed>): ?array<string, mixed>  $callback
     */
    public function modify(callable $callback): bool
    {
        $this->isUpdated = false;
        $path = $this->existingPath();

        $originalContent = $this->files->get($path);
        $rootComposer = $this->decodeRootComposer($originalContent, $path);

        $modified = $callback($rootComposer);

        if ($this->isUnchanged($modified, $rootComposer)) {
            return false;
        }

        $this->store($path, $modified, $originalContent);

        return true;
    }

    /**
     * @param  array<string, mixed>  $modified
     */
    private function store(string $path, $modified, string $originalContent): void
    {
        $this->files->put($path, json_encode($modified, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        $this->isUpdated = true;

        $this->dumpAutoloadWithRollback($path, $originalContent);
    }

    /**
     * @param  array<string, mixed>|null  $modified
     * @param  array<string, mixed>  $rootComposer
     *
     * @phpstan-assert-if-false array<string, mixed> $modified
     */
    private function isUnchanged($modified, array $rootComposer): bool
    {
        return $modified === null || $modified === $rootComposer;
    }

    private function existingPath(): string
    {
        $path = $this->path();
        $this->ensureRootComposerExists($path);

        return $path;
    }

    private function ensureRootComposerExists(string $path): void
    {
        if (! $this->files->isFile($path)) {
            throw new RuntimeException("Root composer.json not found: {$path}");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeRootComposer(string $content, string $path): array
    {
        $data = json_decode($content, true);

        if (! $this->isDecoded($data)) {
            throw new RuntimeException("Invalid root composer.json: {$path}");
        }

        return $data;
    }

    private function dumpAutoloadWithRollback(string $path, string $originalContent): void
    {
        try {
            $this->dumpAutoload();
        } catch (Throwable $e) {
            $this->rollback($path, $originalContent);

            throw $e;
        }
    }

    private function dumpAutoload(): void
    {
        $exitCode = $this->composer->dumpAutoloads();

        if ($exitCode !== 0) {
            throw new RuntimeException("Composer dump-autoload failed with exit code [{$exitCode}].");
        }
    }

    private function rollback(string $path, string $originalContent): void
    {
        if ($this->isUpdated) {
            $this->files->put($path, $originalContent);
            $this->isUpdated = false;
        }
    }

    private function isDecoded(mixed $data): bool
    {
        return is_array($data) && json_last_error() === JSON_ERROR_NONE;
    }
}
