<?php

namespace Studeo\Modular\DataObjects;

use Studeo\Modular\Enums\LivewireComponentType;

final readonly class LivewireComponentOptions
{
    public function __construct(
        public LivewireComponentType $type = LivewireComponentType::Classic,
        public bool $test = true,
        public bool $inline = false,
        public bool $page = false,
        public ?string $layout = null,
        public ?string $title = null,
        public bool $assets = false,
        public ?string $schema = null,
        public ?SchemaDefinition $schemaDefinition = null,
        public ?string $action = null,
        /** @var array<int, string> field=source pairs from --context */
        public array $context = [],
    ) {}

    public function isClassic(): bool
    {
        return $this->type === LivewireComponentType::Classic;
    }

    public function isMfc(): bool
    {
        return $this->type === LivewireComponentType::Mfc;
    }

    public function isSfc(): bool
    {
        return $this->type === LivewireComponentType::Sfc;
    }

    public function hasSchema(): bool
    {
        return $this->schema !== null || $this->schemaDefinition !== null;
    }
}
