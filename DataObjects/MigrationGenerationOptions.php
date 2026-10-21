<?php

namespace Studeo\Modular\DataObjects;

final readonly class MigrationGenerationOptions
{
    /**
     * @param  array<int, string>  $columns
     */
    public function __construct(
        public ?string $create = null,
        public ?string $table = null,
        public bool $blank = false,
        public bool $pivot = false,
        public bool $morph = false,
        public ?string $foreignKey1 = null,
        public ?string $foreignKey2 = null,
        public ?SchemaDefinition $schema = null,
        public array $columns = [],
    ) {}
}
