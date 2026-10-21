<?php

namespace Studeo\Modular\Support;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Throwable;

/**
 * The module cache file: one PHP file returning ['version' => int, 'modules' => [...]],
 * keyed by module key, holding each module's discovered listeners, policies and
 * middleware as plain strings and arrays. Entries are never checked against the
 * module's files; modular:clear is how a stale cache is dropped.
 *
 * Anything unreadable (missing, corrupt, unknown version, malformed entry) reads as
 * absent, so the registrar scans live and artisan is never broken by the file.
 */
final class ModuleCache
{
    public const VERSION = 1;

    /**
     * Read once per process. A long-lived worker (Octane, queue workers) keeps this
     * copy, so it needs a restart after modular:cache or modular:clear.
     *
     * @var array<string, mixed>|null
     */
    private ?array $modules = null;

    public function __construct(
        private readonly string $path,
        private readonly Filesystem $files,
    ) {}

    /**
     * @return array{listeners: array<string, array<int, string>>, policies: array<string, string>, middleware: array<string, string>}|null
     */
    public function entryFor(string $key): ?array
    {
        $entry = $this->modules()[$key] ?? null;

        return $this->isValidEntry($entry) ? $entry : null;
    }

    /**
     * Written to a temp file in the same directory, then renamed over the cache, so a
     * reader never sees a half-written file.
     *
     * @param  array<string, array<string, array<string, mixed>>>  $modules
     */
    public function write(array $modules): void
    {
        $this->files->ensureDirectoryExists(dirname($this->path));
        ksort($modules);

        $temp = @tempnam(dirname($this->path), 'modular');
        $contents = '<?php return '.var_export(['version' => self::VERSION, 'modules' => $modules], true).';'.PHP_EOL;

        if (! $this->publish($temp, $contents)) {
            $this->discard($temp);

            throw new RuntimeException("Unable to write the module cache [{$this->path}].");
        }

        $this->forget();
    }

    /**
     * @return bool whether a file was removed; a missing file is not an error
     *
     * @throws RuntimeException when the file exists and cannot be deleted
     */
    public function clear(): bool
    {
        $existed = $this->files->isFile($this->path);

        if ($existed) {
            $this->delete();
        }

        $this->forget();

        return $existed;
    }

    private function delete(): void
    {
        if (! $this->files->delete($this->path)) {
            throw new RuntimeException("Unable to delete the module cache [{$this->path}].");
        }
    }

    private function forget(): void
    {
        $this->modules = null;

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($this->path, true);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function modules(): array
    {
        return $this->modules ??= $this->load();
    }

    /**
     * @return array<string, mixed>
     */
    private function load(): array
    {
        if (! $this->files->isFile($this->path)) {
            return [];
        }

        // Output is discarded: a file that is not PHP would otherwise be echoed.
        ob_start();

        try {
            $data = (static fn (string $path): mixed => include $path)($this->path);
        } catch (Throwable) {
            // An unreadable, truncated or foreign cache file must read as absent and never break boot.
            $data = null;
        } finally {
            ob_end_clean();
        }

        return $this->modulesOf($data);
    }

    /**
     * @return array<string, mixed>
     */
    private function modulesOf(mixed $data): array
    {
        return is_array($data) && ($data['version'] ?? null) === self::VERSION && is_array($data['modules'] ?? null)
            ? $data['modules']
            : [];
    }

    private function isValidEntry(mixed $entry): bool
    {
        return is_array($entry) && $this->isValid($entry);
    }

    /**
     * Failures are read from the return values: a warning turned into an exception by the
     * framework would skip discard() and leave the temp file behind.
     */
    private function publish(string|false $temp, string $contents): bool
    {
        return $temp !== false && $this->isBesideCache($temp) && @file_put_contents($temp, $contents) !== false && @chmod($temp, 0666 & ~umask()) && @rename($temp, $this->path);
    }

    /**
     * tempnam() falls back to the system temp directory when the target cannot hold the file.
     */
    private function isBesideCache(string $temp): bool
    {
        return realpath(dirname($temp)) === realpath(dirname($this->path));
    }

    private function discard(string|false $temp): void
    {
        if ($temp !== false) {
            @unlink($temp);
        }
    }

    /**
     * @param  array<array-key, mixed>  $entry
     */
    private function isValid(array $entry): bool
    {
        return $this->isMapOf($entry['listeners'] ?? null, fn (mixed $handlers): bool => is_array($handlers) && $this->isListOfStrings($handlers))
            && $this->isMapOf($entry['policies'] ?? null, 'is_string')
            && $this->isMapOf($entry['middleware'] ?? null, 'is_string');
    }

    private function isMapOf(mixed $value, callable $check): bool
    {
        return is_array($value) && array_filter(
            $value,
            fn (mixed $item, mixed $key): bool => ! is_string($key) || ! $check($item),
            ARRAY_FILTER_USE_BOTH
        ) === [];
    }

    /**
     * @param  array<array-key, mixed>  $handlers
     */
    private function isListOfStrings(array $handlers): bool
    {
        return array_filter($handlers, fn (mixed $handler): bool => ! is_string($handler)) === [];
    }
}
