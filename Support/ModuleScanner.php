<?php

namespace Studeo\Modular\Support;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use ReflectionClass;
use Studeo\Modular\DataObjects\LivewireComponentDefinition;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\ModuleManifest;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Finds what a module contains, from the module's own directory and namespace.
 * Class directories are scanned recursively. It only discovers; registering is
 * ModuleRegistrar's job.
 *
 * Given a cached entry (see ModuleCache), listeners, policies and middleware come from
 * it instead of the files. Commands and Livewire components are never cached.
 */
final class ModuleScanner
{
    /**
     * @param  array{listeners: array<string, array<int, string>>, policies: array<string, string>, middleware: array<string, string>}|null  $cached
     */
    public function __construct(
        private readonly string $directory,
        private readonly ModuleManifest $manifest,
        private readonly Filesystem $files,
        private readonly ?array $cached = null,
    ) {}

    /**
     * @return array<int, class-string>
     */
    public function commands(): array
    {
        return array_values(array_filter(
            $this->classesIn('Commands'),
            fn (string $class): bool => is_subclass_of($class, Command::class) && (new ReflectionClass($class))->isInstantiable()
        ));
    }

    /**
     * Event => listeners, using Laravel's listener detection with module namespaces.
     *
     * @return array<string, array<int, string>>
     */
    public function listeners(): array
    {
        if ($this->cached !== null) {
            return $this->cached['listeners'];
        }

        $path = $this->path('Listeners');

        return $this->files->isDirectory($path) ? ModuleListenerDiscovery::within($path, $this->directory) : [];
    }

    /**
     * Model => policy. A policy pairs with the model at the same relative path:
     * Policies/Billing/InvoicePolicy pairs with Models/Billing/Invoice.
     *
     * @return array<class-string, class-string>
     */
    public function policies(): array
    {
        if ($this->cached !== null) {
            return $this->cached['policies'];
        }

        $models = $this->classesIn('Models');
        $policies = $this->classesIn('Policies');
        $modelOf = array_map(fn (int|string $relative): ?string => $this->pairedModel((string) $relative, $models), array_keys($policies));
        $paired = array_filter(array_combine(array_keys($policies), $modelOf), fn (?string $model): bool => $model !== null);

        return array_combine(array_values($paired), array_values(array_intersect_key($policies, $paired)));
    }

    /**
     * @param  array<string, class-string>  $models
     */
    private function pairedModel(string $relative, array $models): ?string
    {
        return Str::endsWith($relative, 'Policy') ? ($models[Str::beforeLast($relative, 'Policy')] ?? null) : null;
    }

    /**
     * Alias => middleware class. The alias is the module key plus the kebab-cased
     * relative path: Api/ThrottleBurst becomes account.api.throttle-burst.
     *
     * @return array<string, class-string>
     */
    public function middleware(): array
    {
        if ($this->cached !== null) {
            return $this->cached['middleware'];
        }

        $aliases = [];

        foreach ($this->classesIn('Middleware') as $relative => $class) {
            $segments = array_map(Str::kebab(...), explode('/', $relative));
            $aliases[$this->manifest->key().'.'.implode('.', $segments)] = $class;
        }

        return $aliases;
    }

    /**
     * The module's Livewire components, to register. Empty unless Livewire is installed.
     *
     * @return array<int, LivewireComponentDefinition>
     */
    public function livewire(LivewireComponentScanner $scanner): array
    {
        if (! class_exists('Livewire\\Livewire')) {
            return [];
        }

        return array_values($scanner->scan(
            new ModuleDefinition($this->manifest->name, $this->manifest->namespace, $this->directory)
        ));
    }

    /**
     * Keyed by path relative to the class directory without the extension
     * (for example "Billing/InvoicePolicy"). Only files whose class exists.
     *
     * @return array<string, class-string>
     */
    private function classesIn(string $classDirectory): array
    {
        $path = $this->path($classDirectory);
        $phpFiles = array_filter($this->files->isDirectory($path) ? $this->files->allFiles($path) : [], fn (SplFileInfo $file): bool => $file->getExtension() === 'php');
        $relatives = array_map(fn (SplFileInfo $file): string => str_replace(DIRECTORY_SEPARATOR, '/', Str::replaceLast('.php', '', $file->getRelativePathname())), $phpFiles);
        $classes = array_combine($relatives, array_map(fn (string $relative): string => $this->manifest->namespace.'\\'.$classDirectory.'\\'.str_replace('/', '\\', $relative), $relatives));
        $classes = array_filter($classes, class_exists(...));

        ksort($classes);

        return $classes;
    }

    private function path(string $relative): string
    {
        return rtrim($this->directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$relative;
    }
}
