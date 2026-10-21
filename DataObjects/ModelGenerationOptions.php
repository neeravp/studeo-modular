<?php

namespace Studeo\Modular\DataObjects;

final readonly class ModelGenerationOptions
{
    public function __construct(
        public bool $withMigration = true,
        public bool $withFactory = true,
        public bool $withSeeder = true,
        public ?string $schemaPath = null,
    ) {}
}
