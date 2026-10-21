<?php

namespace Studeo\Modular\DataObjects;

final readonly class LivewireMoveContext
{
    public function __construct(
        public ModuleDefinition $sourceModule,
        public LivewireComponentDefinition $component,
        public ModuleDefinition $targetModule,
        public string $targetName,
    ) {}

    public function isCrossModule(): bool
    {
        return $this->sourceModule->name !== $this->targetModule->name;
    }

    public function targetDescription(): string
    {
        return $this->isCrossModule()
            ? "[{$this->targetName}] in module [{$this->targetModule->name}]"
            : "[{$this->targetName}]";
    }
}
