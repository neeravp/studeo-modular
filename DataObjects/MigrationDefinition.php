<?php

namespace Studeo\Modular\DataObjects;

use Studeo\Modular\Enums\MigrationType;

final readonly class MigrationDefinition
{
    public function __construct(
        public string $name,
        public MigrationType $type,
        public ?string $tableName = null,
        public ?string $column1 = null,
        public ?string $column2 = null,
        public ?string $morphName = null,
    ) {}
}
