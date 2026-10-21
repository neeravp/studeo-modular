<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateLivewireComponentAction;
use Studeo\Modular\Console\Actions\CreateModuleAction;
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

test('it supports nested component directories', function () {
    $action = app(CreateLivewireComponentAction::class);

    $result = $action->execute($this->blogModule, 'Admin/UserTable');

    $classPath = $this->blogModule->path.'/Livewire/Admin/UserTable.php';
    expect($classPath)->toBeFile()
        ->and($result->component->alias)->toBe('admin.user-table');

    $classContent = File::get($classPath);
    expect($classContent)->toContain('namespace Studeo\Blog\Livewire\Admin;');
});

test('it sanitizes slashes and whitespace in component name', function () {
    $action = app(CreateLivewireComponentAction::class);

    $result = $action->execute($this->blogModule, '  /post_comments/  ');

    $classPath = $this->blogModule->path.'/Livewire/PostComments.php';
    expect($classPath)->toBeFile()
        ->and($result->component->name)->toBe('PostComments')
        ->and($result->component->alias)->toBe('post-comments');
});

test('it throws an exception when module directory does not exist', function () {
    $action = app(CreateLivewireComponentAction::class);

    $ghostModule = new ModuleDefinition(
        name: 'Ghost',
        namespace: 'Studeo\\Ghost',
        path: $this->rootModulesPath.'/Ghost',
    );

    expect(fn () => $action->execute($ghostModule, 'PostList'))
        ->toThrow(RuntimeException::class, "Module directory does not exist [{$ghostModule->path}].");
});

test('it throws an exception when component already exists in module', function () {
    $action = app(CreateLivewireComponentAction::class);

    $action->execute($this->blogModule, 'PostList');

    expect(fn () => $action->execute($this->blogModule, 'PostList'))
        ->toThrow(RuntimeException::class, 'Livewire component [PostList] already exists in module [Blog].');
});

test('it throws an exception on invalid or empty component name', function () {
    $action = app(CreateLivewireComponentAction::class);

    expect(fn () => $action->execute($this->blogModule, '   ///   '))
        ->toThrow(RuntimeException::class, 'Invalid component name [   ///   ].');
});
