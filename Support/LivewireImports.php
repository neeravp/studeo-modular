<?php

namespace Studeo\Modular\Support;

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Studeo\Modular\DataObjects\LivewireComponentOptions;

/**
 * The Livewire classes a generated component names, registered in the file's imports.
 */
final class LivewireImports
{
    /**
     * The names to write for Livewire's Component, and for Layout and Title on a page, each
     * registered in the file's imports.
     *
     * @return array<string, string>
     */
    public function names(PhpImports $imports, LivewireComponentOptions $options): array
    {
        return array_map($imports->name(...), ['component' => Component::class, ...($options->page ? ['layout' => Layout::class, 'title' => Title::class] : [])]);
    }
}
