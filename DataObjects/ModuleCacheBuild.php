<?php

namespace Studeo\Modular\DataObjects;

use Studeo\Modular\Exceptions\ModuleLoadException;

final readonly class ModuleCacheBuild
{
    /**
     * @param  array<string, array<string, array<string, mixed>>>  $modules  entries keyed by module key
     * @param  array<int, ModuleLoadException>  $failures
     */
    public function __construct(
        public array $modules = [],
        public array $failures = [],
    ) {}
}
