<?php

namespace Studeo\Modular\DataObjects;

final readonly class LivewireComponentDefinition
{
    /**
     * @param  array<string>  $files
     */
    public function __construct(
        public ModuleDefinition $module,
        public string $name,
        public string $alias,
        public string $target,
        public string $path,
        public string $type,
        public array $files = [],
    ) {}

    /**
     * @return array<string>
     */
    public function aliases(): array
    {
        $moduleKey = ModuleManifest::keyFor($this->module->name);

        return [
            "{$moduleKey}.{$this->alias}",
            "{$moduleKey}::{$this->alias}",
        ];
    }

    public function isDirectoryBased(): bool
    {
        return $this->type === 'mfc';
    }
}
