<?php

use Studeo\Modular\Tests\Support\InstallerCommandDriver;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->installer = new InstallerCommandDriver(app());
    fakeLivewireInstalled();
    fakeInertiaVueInstalled();
    $this->drive = $this->installer->run(...);
});

afterEach(fn () => $this->installer->tearDown());

it('resolves a Livewire component name', function (string $command, array $input, array $answers, bool $interactive, array $expected) {
    expect(($this->drive)($command, $input, $answers, $interactive))->toBe($expected);
})->with([
    'given' => ['module:livewire', ['name' => 'PostList', '--module' => 'Blog'], [], true, [
        'status' => 0,
        'stdout' => implode("\n", [
            'Livewire component [PostList] created successfully in module [Blog].',
            'Type: classic',
            'Aliases: blog.post-list, blog::post-list',
            'Created files:',
            '  - <module>/Livewire/PostList.php',
            '  - <module>/resources/views/livewire/post-list.blade.php',
            '  - <module>/tests/Feature/Livewire/PostListTest.php',
        ])."\n",
        'stderr' => '',
        'answered' => 0,
        'resolved' => [],
        'processes' => [],
    ]],
    'blank, answered' => ['module:livewire', ['name' => '', '--module' => 'Blog'], ['PostList'], true, [
        'status' => 0,
        'stdout' => implode("\n", [
            '',
            '  What should the Livewire component be named?',
            '❯ ',
            'Livewire component [PostList] created successfully in module [Blog].',
            'Type: classic',
            'Aliases: blog.post-list, blog::post-list',
            'Created files:',
            '  - <module>/Livewire/PostList.php',
            '  - <module>/resources/views/livewire/post-list.blade.php',
            '  - <module>/tests/Feature/Livewire/PostListTest.php',
        ])."\n",
        'stderr' => '',
        'answered' => 9,
        'resolved' => [],
        'processes' => [],
    ]],
    'blank, no terminal' => ['module:livewire', ['name' => '', '--module' => 'Blog'], [], false, [
        'status' => 1,
        'stdout' => "The [name] argument must be given when the command is not interactive, e.g. module:livewire PostList --module=Blog.\n",
        'stderr' => '',
        'answered' => 0,
        'resolved' => [],
        'processes' => [],
    ]],
    'whitespace, answered' => ['module:livewire', ['name' => '   ', '--module' => 'Blog'], ['PostList'], true, [
        'status' => 0,
        'stdout' => implode("\n", [
            '',
            '  What should the Livewire component be named?',
            '❯ ',
            'Livewire component [PostList] created successfully in module [Blog].',
            'Type: classic',
            'Aliases: blog.post-list, blog::post-list',
            'Created files:',
            '  - <module>/Livewire/PostList.php',
            '  - <module>/resources/views/livewire/post-list.blade.php',
            '  - <module>/tests/Feature/Livewire/PostListTest.php',
        ])."\n",
        'stderr' => '',
        'answered' => 9,
        'resolved' => [],
        'processes' => [],
    ]],
    'whitespace, no terminal' => ['module:livewire', ['name' => '   ', '--module' => 'Blog'], [], false, [
        'status' => 1,
        'stdout' => "The [name] argument must be given when the command is not interactive, e.g. module:livewire PostList --module=Blog.\n",
        'stderr' => '',
        'answered' => 0,
        'resolved' => [],
        'processes' => [],
    ]],
    'zero is a given name that a class cannot carry' => ['module:livewire', ['name' => '0', '--module' => 'Blog'], ['Other'], true, [
        'status' => 1,
        'stdout' => "Invalid component name [0].\n",
        'stderr' => '',
        'answered' => 0,
        'resolved' => [],
        'processes' => [],
    ]],
    'missing, answered' => ['module:livewire', ['--module' => 'Blog'], ['PostList'], true, [
        'status' => 0,
        'stdout' => implode("\n", [
            '',
            '  What should the Livewire component be named?',
            '❯ ',
            'Livewire component [PostList] created successfully in module [Blog].',
            'Type: classic',
            'Aliases: blog.post-list, blog::post-list',
            'Created files:',
            '  - <module>/Livewire/PostList.php',
            '  - <module>/resources/views/livewire/post-list.blade.php',
            '  - <module>/tests/Feature/Livewire/PostListTest.php',
        ])."\n",
        'stderr' => '',
        'answered' => 9,
        'resolved' => [],
        'processes' => [],
    ]],
    'missing, no terminal' => ['module:livewire', ['--module' => 'Blog'], [], false, [
        'status' => 1,
        'stdout' => "The [name] argument must be given when the command is not interactive, e.g. module:livewire PostList --module=Blog.\n",
        'stderr' => '',
        'answered' => 0,
        'resolved' => [],
        'processes' => [],
    ]],
]);
