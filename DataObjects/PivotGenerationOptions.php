<?php

namespace Studeo\Modular\DataObjects;

final readonly class PivotGenerationOptions
{
    public function __construct(
        public bool $withMigration = true,
        public ?string $table = null,
        public bool $morph = false,
        public ?string $foreignKey1 = null,
        public ?string $foreignKey2 = null,
    ) {}
}
