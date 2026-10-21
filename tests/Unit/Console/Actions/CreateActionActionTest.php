<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateActionAction;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\DataObjects\ActionGenerationOptions;
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

test('it creates the action and returns its path', function () {
    $result = app(CreateActionAction::class)->execute($this->blogModule, 'CreatePost');

    expect($result->actionPath)->toBe($this->blogModule->path.'/Actions/CreatePostAction.php')
        ->and($result->inputPath)->toBe($this->blogModule->path.'/Data/CreatePostInput.php')
        ->and($result->inputCreated)->toBeTrue()
        ->and(File::get($result->actionPath))->toContain('final class CreatePostAction')
        ->and($result->inputPath)->toBeFile();
});

test('it creates a system action', function () {
    $result = app(CreateActionAction::class)->execute($this->blogModule, 'Recount', new ActionGenerationOptions(system: true));

    expect(File::get($result->actionPath))->toContain('#[SystemAction]')->toContain('__invoke(RecountInput $input): void')->not->toContain('Gate')->not->toContain('Authenticatable');
});

test('it creates an action in a nested subdirectory', function () {
    $result = app(CreateActionAction::class)->execute($this->blogModule, 'Comments/AddComment');

    expect($result->actionPath)->toBe($this->blogModule->path.'/Actions/Comments/AddCommentAction.php')
        ->and(File::get($result->actionPath))->toContain('namespace Studeo\Blog\Actions\Comments;');
});

test('it throws an exception if the action already exists', function () {
    $action = app(CreateActionAction::class);
    $action->execute($this->blogModule, 'PublishPost');

    $action->execute($this->blogModule, 'PublishPost');
})->throws(RuntimeException::class, 'Action [PublishPostAction] already exists in module [Blog].');

test('it throws an exception for a name that is only Action', function () {
    app(CreateActionAction::class)->execute($this->blogModule, 'Action');
})->throws(RuntimeException::class, 'only Action');

test('it throws an exception when the module directory does not exist', function () {
    $missingModule = new ModuleDefinition(
        name: 'Ghost',
        namespace: 'Studeo\\Ghost',
        path: $this->rootModulesPath.'/Ghost',
    );

    app(CreateActionAction::class)->execute($missingModule, 'GhostAction');
})->throws(RuntimeException::class);
