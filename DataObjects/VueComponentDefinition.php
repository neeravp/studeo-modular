<?php

namespace Studeo\Modular\DataObjects;

final readonly class VueComponentDefinition
{
    public function __construct(
        public ModuleDefinition $module,
        public string $name,
        public string $type, // 'component' | 'page'
        public string $path,
    ) {}

    public function isPage(): bool
    {
        return $this->type === 'page';
    }

    public function displayType(): string
    {
        return $this->isPage() ? 'Vue Page' : 'Vue Component';
    }
}
