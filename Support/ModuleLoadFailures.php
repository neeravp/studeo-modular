<?php

namespace Studeo\Modular\Support;

use Studeo\Modular\Exceptions\ModuleLoadException;

/**
 * The failures collected while modules register, shared across the app as a
 * singleton. ModularServiceProvider reports them once the app has booted.
 */
final class ModuleLoadFailures
{
    /** @var array<int, ModuleLoadException> */
    private array $failures = [];

    public function add(ModuleLoadException $failure): void
    {
        $this->failures[] = $failure;
    }

    /**
     * @return array<int, ModuleLoadException>
     */
    public function all(): array
    {
        return $this->failures;
    }
}
