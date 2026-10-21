<?php

namespace Studeo\Modular\DataObjects;

use Studeo\Modular\Enums\MigrationType;

final readonly class MigrationGenerationResult
{
    public function __construct(
        public string $path,
        public string $filename,
        public ?string $tableName,
        public MigrationType $type,
    ) {}
}
