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

it('resolves an enum name', function (string $command, array $input, array $answers, bool $interactive, array $expected) {
    expect(($this->drive)($command, $input, $answers, $interactive))->toBe($expected);
})->with([
    'given' => ['module:enum', ['name' => 'PostStatus', '--module' => 'Blog', '--cases' => 'draft,published'], [], true, [
        'status' => 0,
        'stdout' => implode("\n", [
            'Enum [PostStatus] created successfully in module [Blog].',
            'Path: <module>/Enums/PostStatus.php',
        ])."\n",
        'stderr' => '',
        'answered' => 0,
        'resolved' => [],
        'processes' => [],
    ]],
    'blank, answered' => ['module:enum', ['name' => '', '--module' => 'Blog', '--cases' => 'draft,published'], ['PostStatus'], true, [
        'status' => 0,
        'stdout' => implode("\n", [
            '',
            '  What should the enum be named?',
            '❯ ',
            'Enum [PostStatus] created successfully in module [Blog].',
            'Path: <module>/Enums/PostStatus.php',
        ])."\n",
        'stderr' => '',
        'answered' => 11,
        'resolved' => [],
        'processes' => [],
    ]],
    'blank, no terminal' => ['module:enum', ['name' => '', '--module' => 'Blog', '--cases' => 'draft,published'], [], false, [
        'status' => 1,
        'stdout' => implode("\n", [
            'The enum name is required when the command is not interactive, e.g. PostStatus.',
        ])."\n",
        'stderr' => '',
        'answered' => 0,
        'resolved' => [],
        'processes' => [],
    ]],
    'whitespace, answered' => ['module:enum', ['name' => '   ', '--module' => 'Blog', '--cases' => 'draft,published'], ['PostStatus'], true, [
        'status' => 0,
        'stdout' => implode("\n", [
            '',
            '  What should the enum be named?',
            '❯ ',
            'Enum [PostStatus] created successfully in module [Blog].',
            'Path: <module>/Enums/PostStatus.php',
        ])."\n",
        'stderr' => '',
        'answered' => 11,
        'resolved' => [],
        'processes' => [],
    ]],
    'whitespace, no terminal' => ['module:enum', ['name' => '   ', '--module' => 'Blog', '--cases' => 'draft,published'], [], false, [
        'status' => 1,
        'stdout' => implode("\n", [
            'The enum name is required when the command is not interactive, e.g. PostStatus.',
        ])."\n",
        'stderr' => '',
        'answered' => 0,
        'resolved' => [],
        'processes' => [],
    ]],
    'zero' => ['module:enum', ['name' => '0', '--module' => 'Blog', '--cases' => 'draft,published'], ['Other'], true, [
        'status' => 1,
        'stdout' => implode("\n", [
            'Enum name [0] is not a valid class name. Use letters and digits, starting with a letter, e.g. PostStatus.',
        ])."\n",
        'stderr' => '',
        'answered' => 0,
        'resolved' => [],
        'processes' => [],
    ]],
    'missing, answered' => ['module:enum', ['--module' => 'Blog', '--cases' => 'draft,published'], ['PostStatus'], true, [
        'status' => 0,
        'stdout' => implode("\n", [
            '',
            '  What should the enum be named?',
            '❯ ',
            'Enum [PostStatus] created successfully in module [Blog].',
            'Path: <module>/Enums/PostStatus.php',
        ])."\n",
        'stderr' => '',
        'answered' => 11,
        'resolved' => [],
        'processes' => [],
    ]],
    'missing, no terminal' => ['module:enum', ['--module' => 'Blog', '--cases' => 'draft,published'], [], false, [
        'status' => 1,
        'stdout' => implode("\n", [
            'The enum name is required when the command is not interactive, e.g. PostStatus.',
        ])."\n",
        'stderr' => '',
        'answered' => 0,
        'resolved' => [],
        'processes' => [],
    ]],
]);
