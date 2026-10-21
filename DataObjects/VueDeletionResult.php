<?php

namespace Studeo\Modular\DataObjects;

final readonly class VueDeletionResult
{
    public function __construct(
        public VueComponentDefinition $component,
        public string $deletedFile,
    ) {}
}
