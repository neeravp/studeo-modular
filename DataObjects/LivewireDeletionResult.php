<?php

namespace Studeo\Modular\DataObjects;

final readonly class LivewireDeletionResult
{
    /**
     * @param  array<string>  $deletedFiles
     * @param  array<int, string>  $notices
     */
    public function __construct(
        public LivewireComponentDefinition $component,
        public array $deletedFiles,
        public array $notices = [],
    ) {}
}
