<?php

namespace Studeo\Modular\DataObjects;

final readonly class ModuleDefinition
{
    public function __construct(
        public string $name,
        public string $namespace,
        public string $path,
    ) {}
}
