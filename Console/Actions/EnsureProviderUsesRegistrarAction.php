<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use Studeo\Modular\DataObjects\ModuleManifest;
use Studeo\Modular\Exceptions\LegacyModuleProviderException;

final class EnsureProviderUsesRegistrarAction
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly RenderModuleProviderAction $renderer,
    ) {}

    /**
     * @throws LegacyModuleProviderException when the provider file does not reference ModuleRegistrar
     */
    public function execute(ModuleManifest $manifest, string $providerFile): void
    {
        if (! str_contains($this->files->get($providerFile), 'ModuleRegistrar')) {
            throw LegacyModuleProviderException::forProvider($providerFile, $manifest, $this->renderer->execute($manifest));
        }
    }
}
