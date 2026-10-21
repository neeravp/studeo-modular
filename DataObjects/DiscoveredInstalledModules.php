<?php

namespace Studeo\Modular\DataObjects;

use Studeo\Modular\Exceptions\ModuleLoadException;

final readonly class DiscoveredInstalledModules
{
    /**
     * @param  array<string, InstalledModule>  $installed
     * @param  array<array-key, ModuleLoadException>  $failures
     */
    public function __construct(
        public array $installed = [],
        public array $failures = [],
    ) {}
}
