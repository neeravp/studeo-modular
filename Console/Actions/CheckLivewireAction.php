<?php

namespace Studeo\Modular\Console\Actions;

use Closure;
use Composer\InstalledVersions;
use Studeo\Modular\DataObjects\LivewireStatus;
use Studeo\Modular\Support\MajorVersion;

final class CheckLivewireAction
{
    /**
     * @param  (Closure(): ?string)|null  $version  the installed livewire version, null when absent
     */
    public function __construct(private readonly ?Closure $version = null) {}

    public function execute(): LivewireStatus
    {
        $version = $this->version !== null ? ($this->version)() : $this->installedVersion();

        return new LivewireStatus($version !== null, MajorVersion::of($version));
    }

    private function installedVersion(): ?string
    {
        if (InstalledVersions::isInstalled('livewire/livewire')) {
            return InstalledVersions::getPrettyVersion('livewire/livewire') ?? 'unknown';
        }

        return class_exists('Livewire\\Component') ? 'unknown' : null;
    }
}
