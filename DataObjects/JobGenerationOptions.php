<?php

namespace Studeo\Modular\DataObjects;

final readonly class JobGenerationOptions
{
    public function __construct(
        public bool $sync = false,
        public bool $batched = false,
    ) {}
}
