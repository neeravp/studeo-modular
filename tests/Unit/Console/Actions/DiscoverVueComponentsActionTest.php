<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\Console\Actions\DiscoverVueComponentsAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->rootModulesPath = config('modular.root_path');
    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);

    $this->blogModule = new ModuleDefinition(
        name: 'Blog',
        namespace: 'Studeo\\Blog',
        path: $this->rootModulesPath.'/Blog',
    );

    app(CreateModuleAction::class)->execute($this->blogModule);
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

test('it returns an empty array when no Vue components exist in module', function () {
    $discoverer = app(DiscoverVueComponentsAction::class);
    $components = $discoverer->forModule($this->blogModule);

    expect($components)->toBeEmpty();
});

test('it discovers components and pages in module', function () {
    $discoverer = app(DiscoverVueComponentsAction::class);

    $cardPath = $this->blogModule->path.'/resources/js/Components/PostCard.vue';
    $indexPath = $this->blogModule->path.'/resources/js/Pages/Posts/Index.vue';

    File::ensureDirectoryExists(dirname($cardPath));
    File::ensureDirectoryExists(dirname($indexPath));

    File::put($cardPath, '<template><div>Card</div></template>');
    File::put($indexPath, '<template><div>Index</div></template>');

    $components = $discoverer->forModule($this->blogModule);

    expect($components)->toHaveCount(2)
        ->and(isset($components['Components/PostCard']))->toBeTrue()
        ->and(isset($components['Pages/Posts/Index']))->toBeTrue()
        ->and($components['Components/PostCard']->isPage())->toBeFalse()
        ->and($components['Pages/Posts/Index']->isPage())->toBeTrue();
});

test('it finds component by exact or prefixed name', function () {
    $discoverer = app(DiscoverVueComponentsAction::class);

    $cardPath = $this->blogModule->path.'/resources/js/Components/PostCard.vue';
    $indexPath = $this->blogModule->path.'/resources/js/Pages/Posts/Index.vue';

    File::ensureDirectoryExists(dirname($cardPath));
    File::ensureDirectoryExists(dirname($indexPath));

    File::put($cardPath, '<template><div>Card</div></template>');
    File::put($indexPath, '<template><div>Index</div></template>');

    expect($discoverer->find($this->blogModule, 'PostCard'))->not->toBeNull()
        ->and($discoverer->find($this->blogModule, 'Components/PostCard.vue'))->not->toBeNull()
        ->and($discoverer->find($this->blogModule, 'Posts/Index'))->not->toBeNull()
        ->and($discoverer->find($this->blogModule, 'Pages/Posts/Index.vue'))->not->toBeNull()
        ->and($discoverer->find($this->blogModule, 'NonExistent'))->toBeNull();
});

test('it discovers components for module via execute with module parameter', function () {
    $discoverer = app(DiscoverVueComponentsAction::class);

    $cardPath = $this->blogModule->path.'/resources/js/Components/PostCard.vue';
    File::ensureDirectoryExists(dirname($cardPath));
    File::put($cardPath, '<template><div>Card</div></template>');

    $components = $discoverer->execute($this->blogModule);

    expect($components)->toHaveCount(1)
        ->and(isset($components['Components/PostCard']))->toBeTrue();
});

test('it returns empty array when executing without parameters and no modules are installed', function () {
    $discoverer = app(DiscoverVueComponentsAction::class);

    $components = $discoverer->execute();

    expect($components)->toBeArray();
});
