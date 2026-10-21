<?php

namespace Studeo\Modular\DataObjects;

final readonly class LivewireStatus
{
    public function __construct(public bool $installed, public ?int $major) {}

    public function isInstalled(): bool
    {
        return $this->installed;
    }
}
