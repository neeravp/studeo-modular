<?php

namespace Studeo\Modular\DataObjects;

final readonly class LivewireResolvedLayout
{
    public function __construct(
        public ModuleDefinition $module,
        public string $layoutName,
        public string $viewAttribute,
        public string $filePath,
        public bool $wasCreated = false,
    ) {}

    public function withCreated(bool $created = true): self
    {
        return new self(
            module: $this->module,
            layoutName: $this->layoutName,
            viewAttribute: $this->viewAttribute,
            filePath: $this->filePath,
            wasCreated: $created,
        );
    }
}
