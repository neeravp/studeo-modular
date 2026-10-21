<?php

namespace Studeo\Modular\DataObjects;

final readonly class LivewireMoveResult
{
    /**
     * @param  array<string, string>  $movedFiles  Map of source file path => target file path
     * @param  array<int, string>  $notices
     */
    public function __construct(
        public LivewireComponentDefinition $from,
        public LivewireComponentDefinition $to,
        public array $movedFiles,
        public array $notices = [],
    ) {}
}
