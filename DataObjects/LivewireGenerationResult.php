<?php

namespace Studeo\Modular\DataObjects;

final readonly class LivewireGenerationResult
{
    /**
     * @param  array<string>  $createdFiles
     * @param  array<string>  $reusedFiles  files a form needed that already existed, left untouched
     * @param  array<string>  $notices
     */
    public function __construct(
        public LivewireComponentDefinition $component,
        public array $createdFiles = [],
        public array $reusedFiles = [],
        public array $notices = [],
    ) {}
}
