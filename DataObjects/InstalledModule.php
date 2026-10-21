<?php

namespace Studeo\Modular\DataObjects;

final readonly class InstalledModule
{
    public function __construct(
        public ModuleDefinition $module,
        public ModuleManifest $manifest,
        public string $providerPath,
    ) {}
}
