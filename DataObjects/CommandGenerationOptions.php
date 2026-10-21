<?php

namespace Studeo\Modular\DataObjects;

final readonly class CommandGenerationOptions
{
    public function __construct(
        public ?string $command = null,
    ) {}
}
