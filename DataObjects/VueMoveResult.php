<?php

namespace Studeo\Modular\DataObjects;

final readonly class VueMoveResult
{
    public function __construct(
        public VueComponentDefinition $from,
        public VueComponentDefinition $to,
        public string $fromPath,
        public string $toPath,
    ) {}
}
