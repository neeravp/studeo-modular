<?php

namespace Studeo\Modular\DataObjects;

final readonly class VueGenerationResult
{
    /**
     * @param  array<int, string>  $createdFiles  the page or component, and any Action and Input generated for a form
     * @param  array<int, string>  $reusedFiles  files a form needed that already existed, left untouched
     * @param  array<int, string>  $notices
     */
    public function __construct(
        public string $path,
        public array $createdFiles = [],
        public array $reusedFiles = [],
        public array $notices = [],
    ) {}
}
