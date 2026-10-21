<?php

namespace Studeo\Modular\DataObjects;

final readonly class MailGenerationOptions
{
    public function __construct(
        public ?string $markdown = null,
        public ?string $view = null,
        public bool $queued = false,
    ) {}
}
