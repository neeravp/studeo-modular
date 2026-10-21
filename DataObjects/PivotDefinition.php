<?php

namespace Studeo\Modular\DataObjects;

final readonly class PivotDefinition
{
    public function __construct(
        public string $modelName,
        public string $tableName,
        public string $column1,
        public string $column2,
        public string $secondEntity,
    ) {}
}
