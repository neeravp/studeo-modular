<?php

namespace Studeo\Modular\DataObjects;

use Studeo\Modular\Enums\VueComponentType;

final readonly class VueComponentOptions
{
    public function __construct(
        public VueComponentType $type = VueComponentType::Component,
        public ?string $schema = null,
        public ?SchemaDefinition $schemaDefinition = null,
        public ?string $action = null,
        public ?string $url = null,
    ) {}

    public function isPage(): bool
    {
        return $this->type === VueComponentType::Page || $this->type === VueComponentType::Form;
    }

    public function isForm(): bool
    {
        return $this->type === VueComponentType::Form;
    }

    public function hasSchema(): bool
    {
        return $this->schema !== null || $this->schemaDefinition !== null;
    }
}
