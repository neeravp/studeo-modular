<?php

namespace Studeo\Modular\DataObjects;

final readonly class ModelGenerationResult
{
    public function __construct(
        public string $modelPath,
        public ?string $migrationPath = null,
        public ?string $factoryPath = null,
        public ?string $seederPath = null,
    ) {}
}
