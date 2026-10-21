<?php

namespace Studeo\Modular\DataObjects;

final readonly class NotificationGenerationOptions
{
    public function __construct(
        public ?string $markdown = null,
        public bool $queued = false,
    ) {}
}
