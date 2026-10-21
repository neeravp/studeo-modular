<?php

namespace Studeo\Modular\DataObjects;

final readonly class RuleGenerationOptions
{
    public function __construct(
        public bool $implicit = false,
    ) {}
}
