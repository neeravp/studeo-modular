<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateDtoAction;
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

test('it creates an OutputData in the module Data directory', function () {
    $action = app(CreateDtoAction::class);

    $path = $action->execute($this->blogModule, 'PostData');

    expect($path)->toBe($this->blogModule->path.'/Data/PostData.php')
        ->and(File::exists($path))->toBeTrue();

    $content = File::get($path);
    expect($content)
        ->toContain('namespace Studeo\Blog\Data;')
        ->toContain('use Studeo\Modular\Support\OutputData;')
        ->toContain('final readonly class PostData extends OutputData')
        ->toContain('public function __construct(');
});

test('it creates an InputData with a protected constructor and rules for names ending in Input', function () {
    $action = app(CreateDtoAction::class);

    $path = $action->execute($this->blogModule, 'CreatePostInput');

    expect(File::get($path))
        ->toContain('use Studeo\Modular\Support\InputData;')
        ->toContain('final readonly class CreatePostInput extends InputData')
        ->toContain('protected function __construct(')
        ->toContain('public static function rules(): array');
});

test('it creates a DTO in a nested subdirectory', function () {
    $action = app(CreateDtoAction::class);

    $path = $action->execute($this->blogModule, 'Comments/CommentData');

    expect($path)->toBe($this->blogModule->path.'/Data/Comments/CommentData.php')
        ->and(File::exists($path))->toBeTrue();

    $content = File::get($path);
    expect($content)
        ->toContain('namespace Studeo\Blog\Data\Comments;')
        ->toContain('final readonly class CommentData extends OutputData');
});

test('it throws an exception if the DTO already exists', function () {
    $action = app(CreateDtoAction::class);
    $action->execute($this->blogModule, 'PostData');

    $action->execute($this->blogModule, 'PostData');
})->throws(RuntimeException::class, 'DTO [PostData] already exists in module [Blog].');

test('it throws an exception when the module directory does not exist', function () {
    $missingModule = new ModuleDefinition(
        name: 'Ghost',
        namespace: 'Studeo\\Ghost',
        path: $this->rootModulesPath.'/Ghost',
    );

    app(CreateDtoAction::class)->execute($missingModule, 'GhostData');
})->throws(RuntimeException::class);
