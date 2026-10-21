<?php

namespace Studeo\Modular\DataObjects;

final readonly class ActionGenerationOptions
{
    public function __construct(
        public bool $system = false,
        public ?string $schema = null,
        public ?SchemaDefinition $schemaDefinition = null,
    ) {}
}
