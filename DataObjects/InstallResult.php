<?php

namespace Studeo\Modular\DataObjects;

final readonly class InstallResult
{
    /**
     * @param  array<string>  $installed
     * @param  array<string>  $alreadyInstalled
     * @param  array<string>  $pending
     */
    public function __construct(
        public array $installed = [],
        public array $alreadyInstalled = [],
        public array $pending = [],
    ) {}

    public function hasInstalled(): bool
    {
        return ! empty($this->installed);
    }

    public function hasAlreadyInstalled(): bool
    {
        return ! empty($this->alreadyInstalled);
    }

    public function hasPending(): bool
    {
        return ! empty($this->pending);
    }
}
