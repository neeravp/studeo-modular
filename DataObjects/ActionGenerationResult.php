<?php

namespace Studeo\Modular\DataObjects;

final readonly class ActionGenerationResult
{
    public function __construct(
        public string $actionPath,
        public string $inputPath,
        public bool $inputCreated,
        /** @var array<int, string> */
        public array $notices = [],
    ) {}
}
