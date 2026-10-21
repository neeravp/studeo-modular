<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateCommandAction;
use Studeo\Modular\Console\Actions\CreateLivewireComponentAction;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\Console\Actions\EnsureLivewireLayoutExistsAction;
use Studeo\Modular\Console\Actions\MoveLivewireComponentAction;
use Studeo\Modular\DataObjects\LivewireComponentDefinition;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->rootModulesPath = config('modular.root_path');
    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);

    $this->blog = new ModuleDefinition('Blog', 'Studeo\\Blog', $this->rootModulesPath.'/Blog');
    $this->posts = new ModuleDefinition('BlogPosts', 'Studeo\\BlogPosts', $this->rootModulesPath.'/BlogPosts');

    app(CreateModuleAction::class)->execute($this->blog);
    app(CreateModuleAction::class)->execute($this->posts);
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

it('derives both Livewire alias forms from the kebab module key', function () {
    $component = new LivewireComponentDefinition($this->posts, 'Counter', 'counter', 'Studeo\\BlogPosts\\Livewire\\Counter', '/x', 'classic');

    expect($component->aliases())->toBe(['blog-posts.counter', 'blog-posts::counter']);
});

it('resolves a layout reference written with the kebab key to the multi-word module', function () {
    $resolved = app(EnsureLivewireLayoutExistsAction::class)->execute($this->blog, 'blog-posts::layouts.admin');

    expect($resolved->module->name)->toBe('BlogPosts')
        ->and($resolved->viewAttribute)->toBe('blog-posts::layouts.admin')
        ->and($this->posts->path.'/resources/views/layouts/admin.blade.php')->toBeFile();
});

it('resolves a dot-notated layout reference written with the kebab key', function () {
    $resolved = app(EnsureLivewireLayoutExistsAction::class)->execute($this->blog, 'blog-posts.admin');

    expect($resolved->module->name)->toBe('BlogPosts')
        ->and($resolved->viewAttribute)->toBe('blog-posts::layouts.admin');
});

it('still resolves a layout reference written with the module name', function () {
    $resolved = app(EnsureLivewireLayoutExistsAction::class)->execute($this->blog, 'BlogPosts::layouts.admin');

    expect($resolved->module->name)->toBe('BlogPosts');
});

it('rewrites the view namespace to the kebab key when moving a component to a multi-word module', function () {
    app(CreateLivewireComponentAction::class)->execute($this->blog, 'PostList');

    app(MoveLivewireComponentAction::class)->execute($this->blog, 'PostList', $this->posts, 'PostList');

    expect(File::get($this->posts->path.'/Livewire/PostList.php'))
        ->toContain("view('blog-posts::livewire.post-list')")
        ->not->toContain('blogposts::');
});

it('derives the default command signature prefix from the module key', function () {
    $path = app(CreateCommandAction::class)->execute($this->posts, 'SendInvoices');

    expect(File::get($path))->toContain("'blog-posts:send-invoices'");
});
