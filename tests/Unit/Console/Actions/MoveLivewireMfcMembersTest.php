<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateLivewireComponentAction;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\Console\Actions\MoveLivewireComponentAction;
use Studeo\Modular\DataObjects\LivewireComponentOptions;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Enums\LivewireComponentType;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->root = config('modular.root_path');
    File::deleteDirectory($this->root);
    File::ensureDirectoryExists($this->root);

    $this->blog = new ModuleDefinition('Blog', 'Studeo\\Blog', $this->root.'/Blog');
    $this->shop = new ModuleDefinition('Shop', 'Studeo\\Shop', $this->root.'/Shop');
    app(CreateModuleAction::class)->execute($this->blog);
    app(CreateModuleAction::class)->execute($this->shop);

    app(CreateLivewireComponentAction::class)->execute($this->blog, 'Admin/PostCard', new LivewireComponentOptions(type: LivewireComponentType::Mfc, assets: true));
    $this->source = $this->blog->path.'/Livewire/Admin/⚡post-card';
    File::put($this->source.'/notes.md', 'notes');
    File::put($this->source.'/post-card-extra.txt', 'extra');
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

test('only members that start with the old name are renamed', function () {
    app(MoveLivewireComponentAction::class)->execute($this->blog, 'Admin/PostCard', $this->shop, 'Cards/ProductCard');

    $target = $this->shop->path.'/Livewire/Cards/⚡product-card';

    expect(array_map('basename', File::files($target)))->toEqualCanonicalizing([
        'product-card.php', 'product-card.blade.php', 'product-card.test.php', 'product-card.css', 'product-card.js', 'product-card-extra.txt', 'notes.md',
    ])->and(File::get($target.'/notes.md'))->toBe('notes')
        ->and(File::get($target.'/product-card-extra.txt'))->toBe('extra');
});

test('the result maps each source member to its target in directory order', function () {
    $members = array_map(fn ($file): string => $file->getRealPath(), File::files($this->source));
    $result = app(MoveLivewireComponentAction::class)->execute($this->blog, 'Admin/PostCard', $this->shop, 'Cards/ProductCard');

    expect(array_keys($result->movedFiles))->toBe($members)
        ->and(array_map('basename', array_values($result->movedFiles)))->toBe(array_map(
            fn (string $path): string => str_starts_with(basename($path), 'post-card') ? 'product-card'.substr(basename($path), strlen('post-card')) : basename($path),
            $members,
        ));
});

test('the source directory and its emptied parents are removed up to the Livewire folder', function () {
    app(MoveLivewireComponentAction::class)->execute($this->blog, 'Admin/PostCard', $this->shop, 'ProductCard');

    expect($this->source)->not->toBeDirectory()
        ->and($this->blog->path.'/Livewire/Admin')->not->toBeDirectory()
        ->and($this->blog->path.'/Livewire')->toBeDirectory();
});

test('a layout the page names is created in the target module when the class file moves', function () {
    app(CreateLivewireComponentAction::class)->execute($this->blog, 'Landing', new LivewireComponentOptions(type: LivewireComponentType::Mfc, page: true, layout: 'app'));

    app(MoveLivewireComponentAction::class)->execute($this->blog, 'Pages/Landing', $this->shop, 'Pages/Landing');

    expect(File::get($this->shop->path.'/Livewire/Pages/⚡landing/landing.php'))->toContain("Layout('shop::layouts.app')")
        ->and($this->shop->path.'/resources/views/layouts/app.blade.php')->toBeFile();
});
