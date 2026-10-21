<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\MoveVueComponentAction;
use Studeo\Modular\Tests\Support\ModulesRootFixture;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->fixture = new ModulesRootFixture(usesComposer: false);
    $this->rootModulesPath = $this->fixture->rootPath;
    $this->blogModule = $this->fixture->definition('Blog');
    $this->shopModule = $this->fixture->definition('Shop');
    $this->fixture->modules('Blog', 'Shop');
});

afterEach(fn () => $this->fixture->tearDown());

test('it renames a component within the same module', function () {
    $cardPath = $this->blogModule->path.'/resources/js/Components/PostCard.vue';
    File::ensureDirectoryExists(dirname($cardPath));
    File::put($cardPath, '<template><div>Card</div></template>');

    $action = app(MoveVueComponentAction::class);
    $result = $action->execute($this->blogModule, 'PostCard', $this->blogModule, 'ArticleCard');

    $targetPath = $this->blogModule->path.'/resources/js/Components/ArticleCard.vue';

    expect(File::exists($cardPath))->toBeFalse()
        ->and(File::exists($targetPath))->toBeTrue()
        ->and($result->from->name)->toBe('PostCard')
        ->and($result->to->name)->toBe('ArticleCard');
});

test('it relocates a component into a nested directory and prunes old directory', function () {
    $sourcePath = $this->blogModule->path.'/resources/js/Pages/Admin/Posts/Index.vue';
    File::ensureDirectoryExists(dirname($sourcePath));
    File::put($sourcePath, '<template><div>Index</div></template>');

    $action = app(MoveVueComponentAction::class);
    $result = $action->execute($this->blogModule, 'Admin/Posts/Index', $this->blogModule, 'Dashboard/Index');

    $targetPath = $this->blogModule->path.'/resources/js/Pages/Dashboard/Index.vue';

    expect(File::exists($sourcePath))->toBeFalse()
        ->and(File::exists($targetPath))->toBeTrue()
        ->and(File::isDirectory($this->blogModule->path.'/resources/js/Pages/Admin/Posts'))->toBeFalse()
        ->and($result->to->name)->toBe('Dashboard/Index');
});

test('it moves a component across modules', function () {
    $sourcePath = $this->blogModule->path.'/resources/js/Components/Button.vue';
    File::ensureDirectoryExists(dirname($sourcePath));
    File::put($sourcePath, '<template><button>Click</button></template>');

    $action = app(MoveVueComponentAction::class);
    $result = $action->execute($this->blogModule, 'Button', $this->shopModule, 'Ui/Button');

    $targetPath = $this->shopModule->path.'/resources/js/Components/Ui/Button.vue';

    expect(File::exists($sourcePath))->toBeFalse()
        ->and(File::exists($targetPath))->toBeTrue()
        ->and($result->from->module->name)->toBe('Blog')
        ->and($result->to->module->name)->toBe('Shop')
        ->and($result->to->name)->toBe('Ui/Button');
});

test('it throws an exception when moving to the same name in the same module', function () {
    $cardPath = $this->blogModule->path.'/resources/js/Components/PostCard.vue';
    File::ensureDirectoryExists(dirname($cardPath));
    File::put($cardPath, '<template><div>Card</div></template>');

    $action = app(MoveVueComponentAction::class);
    $action->execute($this->blogModule, 'PostCard', $this->blogModule, 'PostCard');
})->throws(RuntimeException::class, 'Cannot move component [PostCard] to the same name in module [Blog].');

test('it throws an exception when target component already exists', function () {
    $sourcePath = $this->blogModule->path.'/resources/js/Components/PostCard.vue';
    $targetPath = $this->blogModule->path.'/resources/js/Components/ExistingCard.vue';

    File::ensureDirectoryExists(dirname($sourcePath));
    File::put($sourcePath, '<template><div>Source</div></template>');
    File::put($targetPath, '<template><div>Target</div></template>');

    $action = app(MoveVueComponentAction::class);
    $action->execute($this->blogModule, 'PostCard', $this->blogModule, 'ExistingCard');
})->throws(RuntimeException::class, 'Vue Component [ExistingCard] already exists in module [Blog].');

test('it throws an exception when source component does not exist', function () {
    $action = app(MoveVueComponentAction::class);
    $action->execute($this->blogModule, 'GhostCard', $this->blogModule, 'NewCard');
})->throws(RuntimeException::class, 'Vue component [GhostCard] does not exist in module [Blog].');
