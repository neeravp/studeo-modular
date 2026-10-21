<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\Console\Actions\MoveVueComponentAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->root = config('modular.root_path');
    File::deleteDirectory($this->root);
    File::ensureDirectoryExists($this->root);

    $this->blog = new ModuleDefinition('Blog', 'Studeo\\Blog', $this->root.'/Blog');
    $this->shop = new ModuleDefinition('Shop', 'Studeo\\Shop', $this->root.'/Shop');
    $this->ghost = new ModuleDefinition('Ghost', 'Studeo\\Ghost', $this->root.'/Ghost');
    $this->phantom = new ModuleDefinition('Phantom', 'Studeo\\Phantom', $this->root.'/Phantom');

    app(CreateModuleAction::class)->execute($this->blog);
    app(CreateModuleAction::class)->execute($this->shop);

    foreach (['Components/PostCard', 'Components/Taken', 'Pages/Index', 'Pages/Taken'] as $file) {
        File::ensureDirectoryExists(dirname($this->blog->path.'/resources/js/'.$file));
        File::put($this->blog->path.'/resources/js/'.$file.'.vue', '<template><div>'.$file.'</div></template>');
    }

    File::ensureDirectoryExists($this->shop->path.'/resources/js/Components');
    File::put($this->shop->path.'/resources/js/Components/PostCard.vue', '<template><div>shop</div></template>');

    $this->tree = function (): array {
        $tree = [];

        foreach (File::allFiles($this->root, true) as $file) {
            $tree[$file->getPathname()] = md5_file($file->getPathname());
        }

        return $tree;
    };

    $this->move = function (ModuleDefinition $from, string $name, ModuleDefinition $to, string $newName, ?bool $page = null): string {
        $before = ($this->tree)();

        try {
            app(MoveVueComponentAction::class)->execute($from, $name, $to, $newName, $page);
            $message = 'no exception';
        } catch (Throwable $exception) {
            $message = $exception::class.': '.$exception->getMessage();
        }

        expect(($this->tree)())->toBe($before);

        return $message;
    };
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

test('a missing source module is reported by its path', function () {
    expect(($this->move)($this->ghost, 'PostCard', $this->blog, 'Card'))
        ->toBe('RuntimeException: Module directory does not exist ['.$this->ghost->path.'].');
});

test('a missing target module is reported by its path', function () {
    expect(($this->move)($this->blog, 'PostCard', $this->ghost, 'Card'))
        ->toBe('RuntimeException: Module directory does not exist ['.$this->ghost->path.'].');
});

test('the source module is checked before the target module', function () {
    expect(($this->move)($this->ghost, 'PostCard', $this->phantom, 'Card'))
        ->toBe('RuntimeException: Module directory does not exist ['.$this->ghost->path.'].');
});

test('both modules are checked before the source is cleaned or looked up', function () {
    expect(($this->move)($this->blog, 'Missing', $this->ghost, 'Card'))
        ->toBe('RuntimeException: Module directory does not exist ['.$this->ghost->path.'].')
        ->and(($this->move)($this->blog, '///', $this->ghost, 'Card'))
        ->toBe('RuntimeException: Module directory does not exist ['.$this->ghost->path.'].');
});

test('an invalid source name is reported with the raw text', function () {
    expect(($this->move)($this->blog, '///', $this->blog, 'Card'))
        ->toBe('RuntimeException: Invalid component name [///].');
});

test('the source is looked up before the target name is cleaned', function () {
    expect(($this->move)($this->blog, 'Missing', $this->blog, ' / '))
        ->toBe('RuntimeException: Vue component [Missing] does not exist in module [Blog].');
});

test('the target name is cleaned once the source exists', function () {
    expect(($this->move)($this->blog, 'PostCard', $this->blog, ' / '))
        ->toBe('RuntimeException: Invalid component name [ / ].');
});

test('the source name is cleaned before the target name', function () {
    expect(($this->move)($this->blog, '///', $this->blog, ' / '))
        ->toBe('RuntimeException: Invalid component name [///].');
});

test('a missing source is named by the kind that was asked for', function (?bool $page, string $label) {
    expect(($this->move)($this->blog, 'Missing', $this->blog, 'Card', $page))
        ->toBe("RuntimeException: {$label} [Missing] does not exist in module [Blog].");
})->with([
    'any kind' => [null, 'Vue component'],
    'a page' => [true, 'Vue Page'],
    'a component' => [false, 'Vue Component'],
]);

test('a missing source is reported before the same-name rule', function () {
    expect(($this->move)($this->blog, 'Missing', $this->blog, 'Missing'))
        ->toBe('RuntimeException: Vue component [Missing] does not exist in module [Blog].');
});

test('moving onto the same name and kind is refused', function (string $name, ?bool $page) {
    expect(($this->move)($this->blog, $name, $this->blog, $name, $page))
        ->toBe("RuntimeException: Cannot move component [{$name}] to the same name in module [Blog].");
})->with([
    'a component' => ['PostCard', null],
    'a component asked for' => ['PostCard', false],
    'a page' => ['Index', null],
    'a page asked for' => ['Index', true],
]);

test('the same name is refused before the target path is checked', function () {
    expect(($this->move)($this->blog, 'Taken', $this->blog, 'taken', false))
        ->toBe('RuntimeException: Cannot move component [Taken] to the same name in module [Blog].');
});

test('the kind that was asked for also filters the source', function () {
    expect(($this->move)($this->blog, 'PostCard', $this->blog, 'PostCard', true))
        ->toBe('RuntimeException: Vue Page [PostCard] does not exist in module [Blog].')
        ->and(($this->move)($this->blog, 'Index', $this->blog, 'Index', false))
        ->toBe('RuntimeException: Vue Component [Index] does not exist in module [Blog].');
});

test('a name that is both a page and a component resolves to the page', function () {
    expect(($this->move)($this->blog, 'Taken', $this->blog, 'Taken'))
        ->toBe('RuntimeException: Cannot move component [Taken] to the same name in module [Blog].');

    $result = app(MoveVueComponentAction::class)->execute($this->blog, 'Taken', $this->blog, 'Moved');

    expect($result->from->type)->toBe('page')
        ->and($result->toPath)->toBe($this->blog->path.'/resources/js/Pages/Moved.vue')
        ->and($this->blog->path.'/resources/js/Components/Taken.vue')->toBeFile();
});

test('an existing target is reported by the kind it would have', function () {
    expect(($this->move)($this->blog, 'PostCard', $this->blog, 'Taken'))
        ->toBe('RuntimeException: Vue Component [Taken] already exists in module [Blog].')
        ->and(($this->move)($this->blog, 'Index', $this->blog, 'Taken'))
        ->toBe('RuntimeException: Vue Page [Taken] already exists in module [Blog].');
});

test('the same name in another module is not the same-name rule', function () {
    expect(($this->move)($this->blog, 'PostCard', $this->shop, 'PostCard'))
        ->toBe('RuntimeException: Vue Component [PostCard] already exists in module [Shop].');
});

test('a page keeps its kind unless the other is asked for', function () {
    $result = app(MoveVueComponentAction::class)->execute($this->blog, 'Index', $this->shop, 'Home');

    expect($result->toPath)->toBe($this->shop->path.'/resources/js/Pages/Home.vue')
        ->and($result->to->type)->toBe('page')
        ->and($result->from->module->name)->toBe('Blog');
});
