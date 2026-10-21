<?php

namespace Studeo\Modular\DataObjects;

final readonly class NotificationGenerationResult
{
    public function __construct(
        public string $notificationPath,
        public ?string $viewPath = null,
    ) {}
}
