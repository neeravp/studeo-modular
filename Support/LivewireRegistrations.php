<?php

namespace Studeo\Modular\Support;

use Closure;
use Livewire\Livewire;
use Studeo\Modular\DataObjects\LivewireComponentDefinition;
use Studeo\Modular\DataObjects\ModuleManifest;

/**
 * What a module registers with Livewire 4, as named steps the registrar runs one by one: a
 * namespace for the {key}::{alias} form (Livewire resolves that form only through a namespace),
 * then one explicit {key}.{alias} per component, a class by name and a single-file or multi-file
 * component by its path.
 */
final class LivewireRegistrations
{
    /**
     * @param  array<int, LivewireComponentDefinition>  $components
     * @return array<string, Closure(): void> the item named in a failure => what registers it
     */
    public static function for(ModuleManifest $manifest, string $livewireDirectory, array $components): array
    {
        $key = $manifest->key();
        $steps = $components === [] ? [] : [$key => fn () => Livewire::addNamespace(
            $key,
            viewPath: $livewireDirectory,
            classNamespace: $manifest->namespace.'\\Livewire',
        )];

        foreach ($components as $component) {
            $alias = "{$key}.{$component->alias}";

            $steps[$alias] = fn () => $component->type === 'classic'
                ? Livewire::component($alias, $component->target)
                : Livewire::addComponent($alias, viewPath: $component->path);
        }

        return $steps;
    }
}
