<?php

namespace Studeo\Modular\DataObjects;

/**
 * A planned Form move: where the Form goes, its rewritten text, and the class names the
 * component's own lines change from and to.
 */
final readonly class LivewireFormMove
{
    public function __construct(
        public string $from,
        public string $to,
        public string $content,
        public string $fromClass,
        public string $toClass,
        public string $formsRoot,
    ) {}
}
