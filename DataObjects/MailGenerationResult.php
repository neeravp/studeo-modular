<?php

namespace Studeo\Modular\DataObjects;

final readonly class MailGenerationResult
{
    public function __construct(
        public string $mailPath,
        public ?string $viewPath = null,
    ) {}
}
