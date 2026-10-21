<?php

namespace Studeo\Modular\Exceptions;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * An installed module could not be loaded at boot.
 */
final class ModuleLoadException extends RuntimeException
{
    public function __construct(
        public readonly string $module,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function invalidManifest(string $module, InvalidModuleManifestException $previous): self
    {
        return new self($module, "Module [{$module}] failed to load: {$previous->getMessage()}", $previous);
    }

    public static function invalidName(string $module, InvalidArgumentException $previous): self
    {
        return new self($module, "Module [{$module}] failed to load: {$previous->getMessage()}", $previous);
    }

    public static function directoryMissing(string $module, string $path): self
    {
        return new self($module, "Module [{$module}] failed to load: directory [{$path}] not found, but it is still mapped in composer.json. Run [php artisan module:uninstall {$module}].");
    }

    public static function providerMissing(string $module, string $path): self
    {
        return new self($module, "Module [{$module}] failed to load: service provider file not found: {$path}");
    }

    /**
     * The provider class cannot be autoloaded. `$declaredNamespace` is the namespace the provider file
     * declares, or null when it could not be read; it differs from the manifest's when the file was
     * edited, and then no autoloader refresh helps until the two are made equal.
     */
    public static function providerClassMissing(string $module, string $provider, string $manifestNamespace, ?string $declaredNamespace): self
    {
        $fix = self::declaresOtherNamespace($declaredNamespace, $manifestNamespace)
            ? "The provider file declares namespace [{$declaredNamespace}], but its namespace must equal the manifest's namespace [{$manifestNamespace}] in module.json. Change one to match the other, then run [composer dump-autoload]."
            : "The class may be missing from the autoloader: run [composer dump-autoload]. If that does not help, the provider's namespace must equal the manifest's namespace [{$manifestNamespace}].";

        return new self($module, "Module [{$module}] failed to load: provider class [{$provider}] not found. {$fix}");
    }

    /**
     * Whether the provider file declares a namespace and it is not the manifest's.
     */
    private static function declaresOtherNamespace(?string $declaredNamespace, string $manifestNamespace): bool
    {
        return $declaredNamespace !== null && $declaredNamespace !== $manifestNamespace;
    }

    public static function legacyProvider(string $module, LegacyModuleProviderException $previous): self
    {
        return new self($module, "Module [{$module}] failed to load: {$previous->getMessage()}", $previous);
    }

    public static function registrationFailed(string $module, ModuleRegistrationException $previous): self
    {
        return new self($module, "Module [{$module}] failed to load: {$previous->getMessage()}", $previous);
    }

    public static function rootComposerUnreadable(RuntimeException $previous): self
    {
        return new self('composer.json', "Installed modules could not be read: {$previous->getMessage()}", $previous);
    }
}
