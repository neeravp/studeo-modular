<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\ModuleManifest;
use Studeo\Modular\Exceptions\InvalidModuleManifestException;

final class DiscoverModulesAction
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly ResolveModuleAction $resolver,
    ) {}

    /**
     * Modules with a usable module.json, by name.
     *
     * @return array<string, ModuleDefinition>
     */
    public function execute(): array
    {
        return $this->scan()[0];
    }

    /**
     * Directories under the modules root that are not discoverable modules: no module.json (a
     * legacy composer.json gets its fix), or a module.json that cannot be used.
     *
     * @return array<string, string> directory name => reason
     */
    public function skipped(): array
    {
        return $this->scan()[1];
    }

    /**
     * @return array{0: array<string, ModuleDefinition>, 1: array<string, string>}
     */
    private function scan(): array
    {
        $path = $this->resolveRootPath();
        $directories = $this->files->isDirectory($path) ? $this->files->directories($path) : [];
        $outcomes = array_combine(array_map('basename', $directories), array_map($this->outcome(...), $directories));
        $modules = array_filter($outcomes, fn (ModuleDefinition|string $outcome): bool => $outcome instanceof ModuleDefinition);
        /** @var array<string, string> $skipped */
        $skipped = array_diff_key($outcomes, $modules);

        ksort($modules);
        ksort($skipped);

        return [$modules, $skipped];
    }

    private function outcome(string $directory): ModuleDefinition|string
    {
        return $this->hasManifest($directory) ? $this->resolve($directory) : $this->skipReason($directory);
    }

    private function resolve(string $directory): ModuleDefinition|string
    {
        try {
            return $this->resolver->execute(basename($directory));
        } catch (InvalidModuleManifestException|InvalidArgumentException $exception) {
            return $this->unusableReason($exception);
        }
    }

    private function unusableReason(InvalidModuleManifestException|InvalidArgumentException $exception): string
    {
        return ($exception instanceof InvalidModuleManifestException ? 'invalid module.json: ' : 'invalid module name: ').$exception->getMessage();
    }

    private function skipReason(string $directory): string
    {
        return $this->files->isFile($directory.DIRECTORY_SEPARATOR.'composer.json')
            ? 'legacy composer.json found, no module.json. Run [php artisan module:install '.basename($directory).'] to see the module.json to create.'
            : 'no module.json.';
    }

    private function hasManifest(string $directory): bool
    {
        return $this->files->isFile($directory.DIRECTORY_SEPARATOR.ModuleManifest::FILE);
    }

    private function resolveRootPath(): string
    {
        return rtrim(
            (string) config('modular.root_path'),
            DIRECTORY_SEPARATOR
        );
    }
}
