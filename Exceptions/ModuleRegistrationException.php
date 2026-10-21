<?php

namespace Studeo\Modular\Exceptions;

use Exception;
use RuntimeException;

/**
 * A module's registrar could not register part of the module. Caught only in
 * ModularServiceProvider and routed through the load-failure path.
 */
final class ModuleRegistrationException extends RuntimeException
{
    public static function invalidManifest(string $directory, InvalidModuleManifestException $previous): self
    {
        return new self("Cannot register the module in [{$directory}]: {$previous->getMessage()}", 0, $previous);
    }

    public static function livewireComponentFailed(string $module, string $alias, Exception $previous): self
    {
        return new self("Module [{$module}] cannot register the Livewire component [{$alias}]: {$previous->getMessage()}", 0, $previous);
    }

    public static function middlewareAliasTaken(string $module, string $alias, string $existing, string $class): self
    {
        return new self(
            "Module [{$module}] cannot alias middleware [{$class}] as [{$alias}]: the alias is already bound to [{$existing}]."
        );
    }
}
