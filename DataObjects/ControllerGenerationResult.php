<?php

namespace Studeo\Modular\DataObjects;

final readonly class ControllerGenerationResult
{
    /**
     * @param  array<int, string>  $created  files written besides the controller
     * @param  array<int, string>  $reused
     * @param  array<int, string>  $notices
     */
    public function __construct(
        public string $path,
        public string $module,
        public array $created = [],
        public array $reused = [],
        public array $notices = [],
        public ?string $route = null,
    ) {}
}
