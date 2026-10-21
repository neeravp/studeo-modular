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
    $this->ghost = new ModuleDefinition('Ghost', 'Studeo\\Ghost', $this->root.'/Ghost');
    $this->otherGhost = new ModuleDefinition('Phantom', 'Studeo\\Phantom', $this->root.'/Phantom');

    app(CreateModuleAction::class)->execute($this->blog);
    app(CreateModuleAction::class)->execute($this->shop);
    app(CreateLivewireComponentAction::class)->execute($this->blog, 'PostList');
    app(CreateLivewireComponentAction::class)->execute($this->blog, 'Existing');
    app(CreateLivewireComponentAction::class)->execute($this->blog, 'Notice', new LivewireComponentOptions(type: LivewireComponentType::Sfc));
    app(CreateLivewireComponentAction::class)->execute($this->shop, 'PostList');

    $this->tree = function (): array {
        $tree = [];

        foreach (File::allFiles($this->root, true) as $file) {
            $tree[$file->getPathname()] = md5_file($file->getPathname());
        }

        return $tree;
    };

    $this->failure = function (callable $call): string {
        try {
            $call();
        } catch (Throwable $exception) {
            return $exception::class.': '.$exception->getMessage();
        }

        return 'no exception';
    };

    $this->move = function (ModuleDefinition $from, string $name, ModuleDefinition $to, string $newName): string {
        $before = ($this->tree)();
        $message = ($this->failure)(fn () => app(MoveLivewireComponentAction::class)->execute($from, $name, $to, $newName));

        expect(($this->tree)())->toBe($before);

        return $message;
    };
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

test('a missing source module is reported by its path', function () {
    expect(($this->move)($this->ghost, 'PostList', $this->blog, 'AllPosts'))
        ->toBe('RuntimeException: Module directory does not exist ['.$this->ghost->path.'].');
});

test('a missing target module is reported by its path', function () {
    expect(($this->move)($this->blog, 'PostList', $this->ghost, 'AllPosts'))
        ->toBe('RuntimeException: Module directory does not exist ['.$this->ghost->path.'].');
});

test('the source module is checked before the target module', function () {
    expect(($this->move)($this->ghost, 'PostList', $this->otherGhost, 'AllPosts'))
        ->toBe('RuntimeException: Module directory does not exist ['.$this->ghost->path.'].');
});

test('both modules are checked before either name is cleaned', function () {
    expect(($this->move)($this->ghost, '///', $this->blog, 'AllPosts'))
        ->toBe('RuntimeException: Module directory does not exist ['.$this->ghost->path.'].')
        ->and(($this->move)($this->blog, '///', $this->ghost, 'AllPosts'))
        ->toBe('RuntimeException: Module directory does not exist ['.$this->ghost->path.'].')
        ->and(($this->move)($this->blog, 'PostList', $this->ghost, ' / '))
        ->toBe('RuntimeException: Module directory does not exist ['.$this->ghost->path.'].');
});

test('an invalid source name is reported with the raw text', function () {
    expect(($this->move)($this->blog, '///', $this->blog, 'AllPosts'))
        ->toBe('RuntimeException: Invalid component name [///].');
});

test('an invalid target name is reported with the raw text', function () {
    expect(($this->move)($this->blog, 'PostList', $this->blog, ' / '))
        ->toBe('RuntimeException: Invalid component name [ / ].');
});

test('the source name is cleaned before the target name', function () {
    expect(($this->move)($this->blog, '///', $this->blog, ' / '))
        ->toBe('RuntimeException: Invalid component name [///].');
});

test('both names are cleaned before the source is looked up', function () {
    expect(($this->move)($this->blog, 'Ghost', $this->blog, ' / '))
        ->toBe('RuntimeException: Invalid component name [ / ].');
});

test('moving a component onto itself is refused whatever the spelling', function (string $from, string $to) {
    expect(($this->move)($this->blog, $from, $this->blog, $to))
        ->toBe('RuntimeException: Cannot move component [PostList] to the same name in module [Blog].');
})->with([
    'same spelling' => ['PostList', 'PostList'],
    'kebab and studly' => ['post-list', 'PostList'],
]);

test('the same name is refused before the source is looked up', function () {
    expect(($this->move)($this->blog, 'Ghost', $this->blog, 'ghost'))
        ->toBe('RuntimeException: Cannot move component [Ghost] to the same name in module [Blog].');
});

test('a missing source is reported before an existing target', function () {
    expect(($this->move)($this->blog, 'Ghost', $this->blog, 'Existing'))
        ->toBe('RuntimeException: Livewire component [Ghost] does not exist in module [Blog].');
});

test('a missing source is reported in the source module', function () {
    expect(($this->move)($this->shop, 'Existing', $this->blog, 'AllPosts'))
        ->toBe('RuntimeException: Livewire component [Existing] does not exist in module [Shop].');
});

test('an existing target is reported in the target module', function () {
    expect(($this->move)($this->blog, 'PostList', $this->shop, 'PostList'))
        ->toBe('RuntimeException: Livewire component [PostList] already exists in module [Shop].');
});

test('an existing target of another shape counts as existing', function () {
    expect(($this->move)($this->blog, 'PostList', $this->blog, 'Notice'))
        ->toBe('RuntimeException: Livewire component [Notice] already exists in module [Blog].');
});

test('the target is looked up by its cleaned name', function () {
    expect(($this->move)($this->blog, 'post-list', $this->blog, 'existing'))
        ->toBe('RuntimeException: Livewire component [Existing] already exists in module [Blog].');
});
