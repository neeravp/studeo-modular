<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;

/**
 * Turns what a command was given for a module into the name of a directory under the modules root:
 * a name, or the folder of a module directly under the root, written relative to the application
 * or in full. Anything else is refused with the modules that exist.
 */
final class ResolveModuleArgumentAction
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly DiscoverModulesAction $discovery,
    ) {}

    /**
     * @throws InvalidArgumentException when the argument names no directory under the modules root
     */
    public function execute(?string $argument): ?string
    {
        return $argument === null ? null : $this->ensureIsModuleDirectory($argument, $this->nameOf($argument));
    }

    private function ensureIsModuleDirectory(string $argument, string $name): string
    {
        return in_array($name, $this->directoryNames(), true) ? $name : throw new InvalidArgumentException($this->refusal($argument));
    }

    private function nameOf(string $argument): string
    {
        $path = str_replace('\\', '/', $argument);

        return $this->isModuleFolder($this->withoutLeadingDots($path)) ? basename($path) : $argument;
    }

    private function isModuleFolder(string $path): bool
    {
        return str_contains($path, '/') && in_array(dirname($path), $this->moduleRoots(), true);
    }

    private function withoutLeadingDots(string $path): string
    {
        return preg_replace('#^(\./)+#', '', $path) ?? $path;
    }

    /**
     * @return list<string>
     */
    private function directoryNames(): array
    {
        $root = rtrim((string) config('modular.root_path'), DIRECTORY_SEPARATOR);

        return $this->files->isDirectory($root) ? array_values(array_map('basename', $this->files->directories($root))) : [];
    }

    private function refusal(string $argument): string
    {
        $modules = array_keys($this->discovery->execute());
        $available = $modules === [] ? 'No modules exist yet; create one with [php artisan module:make Blog].' : 'Modules: '.implode(', ', $modules).'.';

        return "[{$argument}] is not a module. Pass a module name or its folder under {$this->moduleRoots()[1]}. {$available}";
    }

    /**
     * The modules root as the full path and as the application's folder-relative path.
     *
     * @return array{0: string, 1: string}
     */
    private function moduleRoots(): array
    {
        $full = rtrim(str_replace('\\', '/', (string) config('modular.root_path')), '/');
        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';

        return [$full, str_starts_with($full, $base) ? substr($full, strlen($base)) : $full];
    }
}
