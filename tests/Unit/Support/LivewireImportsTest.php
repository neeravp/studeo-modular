<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Studeo\Modular\DataObjects\LivewireComponentOptions;
use Studeo\Modular\Support\LivewireImports;
use Studeo\Modular\Support\PhpImports;

test('it names the component class for a plain component', function () {
    $imports = new PhpImports('Studeo\\Blog\\Livewire');

    $names = (new LivewireImports)->names($imports, new LivewireComponentOptions);

    expect($names)->toBe(['component' => 'Component']);
});

test('it also names layout and title for a page', function () {
    $imports = new PhpImports('Studeo\\Blog\\Livewire');

    $names = (new LivewireImports)->names($imports, new LivewireComponentOptions(page: true));

    expect($names)->toBe(['component' => 'Component', 'layout' => 'Layout', 'title' => 'Title']);
});

test('it registers each name in the imports of the file', function () {
    $imports = new PhpImports('Studeo\\Blog\\Livewire');

    (new LivewireImports)->names($imports, new LivewireComponentOptions(page: true));

    expect($imports->name(Component::class))->toBe('Component')
        ->and($imports->name(Layout::class))->toBe('Layout')
        ->and($imports->name(Title::class))->toBe('Title');
});

test('it writes a fully qualified name when the short name is taken', function () {
    $imports = new PhpImports('Studeo\\Blog\\Livewire');
    $imports->name('App\\Other\\Component');

    $names = (new LivewireImports)->names($imports, new LivewireComponentOptions);

    expect($names)->toBe(['component' => '\\'.Component::class]);
});
