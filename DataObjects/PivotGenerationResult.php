<?php

namespace Studeo\Modular\DataObjects;

final readonly class PivotGenerationResult
{
    public function __construct(
        public string $modelPath,
        public ?string $migrationPath,
        public string $modelName,
        public string $tableName,
    ) {}
}
