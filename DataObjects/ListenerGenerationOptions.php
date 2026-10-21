<?php

namespace Studeo\Modular\DataObjects;

final readonly class ListenerGenerationOptions
{
    public function __construct(
        public ?string $event = null,
        public bool $queued = false,
    ) {}
}
