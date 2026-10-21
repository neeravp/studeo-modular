<?php

namespace Studeo\Modular\DataObjects;

use Studeo\Modular\Enums\ControllerType;

final readonly class ControllerGenerationOptions
{
    /**
     * @param  array<int, string>  $context  --context values, each field=actor
     */
    public function __construct(
        public ControllerType $type = ControllerType::Invokable,
        public ?string $model = null,
        public bool $inertia = false,
        public ?string $action = null,
        public ?string $schema = null,
        public array $context = [],
        public ?string $url = null,
    ) {}
}
