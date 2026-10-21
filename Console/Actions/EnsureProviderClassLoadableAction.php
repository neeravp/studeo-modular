<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use Studeo\Modular\DataObjects\InstalledModule;
use Studeo\Modular\Exceptions\ModuleLoadException;

/**
 * Checks, before a module's provider is registered, that the class can be autoloaded. Only the
 * missing class is reported (a stale autoloader, or a namespace that is not the manifest's):
 * the autoload itself still runs the provider file, so any other error in it propagates.
 */
final class EnsureProviderClassLoadableAction
{
    public function __construct(
        private readonly Filesystem $files,
    ) {}

    /**
     * @throws ModuleLoadException when the provider class does not exist
     */
    public function execute(InstalledModule $installed): void
    {
        $provider = $installed->manifest->provider();

        if (! class_exists($provider)) {
            throw ModuleLoadException::providerClassMissing($installed->module->name, $provider, $installed->manifest->namespace, $this->declaredNamespace($installed->providerPath));
        }
    }

    private function declaredNamespace(string $providerFile): ?string
    {
        return preg_match('/^\s*namespace\s+([^\s;{]+)/m', $this->files->get($providerFile), $match) === 1 ? $match[1] : null;
    }
}
