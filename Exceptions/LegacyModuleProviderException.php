<?php

namespace Studeo\Modular\Exceptions;

use RuntimeException;
use Studeo\Modular\DataObjects\ModuleManifest;

/**
 * A module's service provider predates ModuleRegistrar, so nothing in the module
 * would be registered. The message prints the exact provider to paste in.
 */
final class LegacyModuleProviderException extends RuntimeException
{
    public static function forProvider(string $providerFile, ModuleManifest $manifest, string $replacement): self
    {
        return new self(
            "The service provider {$providerFile} does not use ModuleRegistrar, so none of the module is registered "
            ."(config, routes, views, migrations, translations, commands, listeners, policies, middleware, Livewire).\n"
            ."Replace its contents with:\n\n{$replacement}\n"
            .self::keyNote($manifest)
        );
    }

    private static function keyNote(ModuleManifest $manifest): string
    {
        $multiWord = $manifest->key() !== strtolower($manifest->name);
        $before = $multiWord ? strtolower($manifest->name) : 'blogposts';
        $after = $multiWord ? $manifest->key() : 'blog-posts';

        return "Note: module keys are now kebab case. For a multi-word module, config/{$before}.php becomes config/{$after}.php "
            ."and the view namespace becomes {$after}::. The same key names Livewire aliases ({$after}.component, {$after}::component) "
            .'and Vue component keys. Rename existing files and update references.';
    }
}
