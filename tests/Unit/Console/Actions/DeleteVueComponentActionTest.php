<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\Console\Actions\DeleteVueComponentAction;
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

test('it deletes a component file and returns deletion result', function () {
    $cardPath = $this->blogModule->path.'/resources/js/Components/PostCard.vue';
    File::ensureDirectoryExists(dirname($cardPath));
    File::put($cardPath, '<template><div>Card</div></template>');

    $action = app(DeleteVueComponentAction::class);
    $result = $action->execute($this->blogModule, 'PostCard');

    expect(File::exists($cardPath))->toBeFalse()
        ->and($result->component->name)->toBe('PostCard')
        ->and($result->deletedFile)->toBe($cardPath);
});

test('it deletes an Inertia page and prunes empty parent directory', function () {
    $pagePath = $this->blogModule->path.'/resources/js/Pages/Admin/Posts/Index.vue';
    File::ensureDirectoryExists(dirname($pagePath));
    File::put($pagePath, '<template><div>Index</div></template>');

    $action = app(DeleteVueComponentAction::class);
    $result = $action->execute($this->blogModule, 'Admin/Posts/Index', isPage: true);

    expect(File::exists($pagePath))->toBeFalse()
        ->and(File::isDirectory($this->blogModule->path.'/resources/js/Pages/Admin/Posts'))->toBeFalse()
        ->and(File::isDirectory($this->blogModule->path.'/resources/js/Pages'))->toBeTrue();
});

test('it does not prune parent directory if other files remain', function () {
    $indexPath = $this->blogModule->path.'/resources/js/Pages/Posts/Index.vue';
    $showPath = $this->blogModule->path.'/resources/js/Pages/Posts/Show.vue';

    File::ensureDirectoryExists(dirname($indexPath));
    File::put($indexPath, '<template><div>Index</div></template>');
    File::put($showPath, '<template><div>Show</div></template>');

    $action = app(DeleteVueComponentAction::class);
    $action->execute($this->blogModule, 'Posts/Index', isPage: true);

    expect(File::exists($indexPath))->toBeFalse()
        ->and(File::exists($showPath))->toBeTrue()
        ->and(File::isDirectory($this->blogModule->path.'/resources/js/Pages/Posts'))->toBeTrue();
});

test('it throws an exception when component does not exist in module', function () {
    $action = app(DeleteVueComponentAction::class);

    $action->execute($this->blogModule, 'NonExistent');
})->throws(RuntimeException::class, 'Vue component [NonExistent] does not exist in module [Blog].');

test('it throws an exception when module directory does not exist', function () {
    $missingModule = new ModuleDefinition(
        name: 'Ghost',
        namespace: 'Studeo\\Ghost',
        path: $this->rootModulesPath.'/Ghost',
    );

    app(DeleteVueComponentAction::class)->execute($missingModule, 'PostCard');
})->throws(RuntimeException::class);
