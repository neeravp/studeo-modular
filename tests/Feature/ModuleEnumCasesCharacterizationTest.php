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

it('resolves enum cases', function (string $command, array $input, array $answers, bool $interactive, array $expected) {
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
    'blank, answered' => ['module:enum', ['name' => 'PostStatus', '--module' => 'Blog', '--cases' => ''], ['draft,published'], true, [
        'status' => 0,
        'stdout' => implode("\n", [
            '',
            '  What are the cases?',
            '❯ ',
            'Enum [PostStatus] created successfully in module [Blog].',
            'Path: <module>/Enums/PostStatus.php',
        ])."\n",
        'stderr' => '',
        'answered' => 16,
        'resolved' => [],
        'processes' => [],
    ]],
    'blank, no terminal' => ['module:enum', ['name' => 'PostStatus', '--module' => 'Blog', '--cases' => ''], [], false, [
        'status' => 1,
        'stdout' => implode("\n", [
            'The cases are required when the command is not interactive, e.g. --cases=draft,published. Valid: lower snake case values, each starting with a letter and at most 30 characters, separated by commas, e.g. --cases=draft,published or --cases=draft,in_review,published.',
        ])."\n",
        'stderr' => '',
        'answered' => 0,
        'resolved' => [],
        'processes' => [],
    ]],
    'whitespace, answered' => ['module:enum', ['name' => 'PostStatus', '--module' => 'Blog', '--cases' => '   '], ['draft,published'], true, [
        'status' => 0,
        'stdout' => implode("\n", [
            '',
            '  What are the cases?',
            '❯ ',
            'Enum [PostStatus] created successfully in module [Blog].',
            'Path: <module>/Enums/PostStatus.php',
        ])."\n",
        'stderr' => '',
        'answered' => 16,
        'resolved' => [],
        'processes' => [],
    ]],
    'whitespace, no terminal' => ['module:enum', ['name' => 'PostStatus', '--module' => 'Blog', '--cases' => '   '], [], false, [
        'status' => 1,
        'stdout' => implode("\n", [
            'The cases are required when the command is not interactive, e.g. --cases=draft,published. Valid: lower snake case values, each starting with a letter and at most 30 characters, separated by commas, e.g. --cases=draft,published or --cases=draft,in_review,published.',
        ])."\n",
        'stderr' => '',
        'answered' => 0,
        'resolved' => [],
        'processes' => [],
    ]],
    'zero' => ['module:enum', ['name' => 'PostStatus', '--module' => 'Blog', '--cases' => '0'], ['draft,published'], true, [
        'status' => 1,
        'stdout' => implode("\n", [
            'Value [0] is not lower snake case. Valid: lower snake case values, each starting with a letter and at most 30 characters, separated by commas, e.g. --cases=draft,published or --cases=draft,in_review,published.',
        ])."\n",
        'stderr' => '',
        'answered' => 0,
        'resolved' => [],
        'processes' => [],
    ]],
    'missing, answered' => ['module:enum', ['name' => 'PostStatus', '--module' => 'Blog'], ['draft,published'], true, [
        'status' => 0,
        'stdout' => implode("\n", [
            '',
            '  What are the cases?',
            '❯ ',
            'Enum [PostStatus] created successfully in module [Blog].',
            'Path: <module>/Enums/PostStatus.php',
        ])."\n",
        'stderr' => '',
        'answered' => 16,
        'resolved' => [],
        'processes' => [],
    ]],
    'missing, no terminal' => ['module:enum', ['name' => 'PostStatus', '--module' => 'Blog'], [], false, [
        'status' => 1,
        'stdout' => implode("\n", [
            'The cases are required when the command is not interactive, e.g. --cases=draft,published. Valid: lower snake case values, each starting with a letter and at most 30 characters, separated by commas, e.g. --cases=draft,published or --cases=draft,in_review,published.',
        ])."\n",
        'stderr' => '',
        'answered' => 0,
        'resolved' => [],
        'processes' => [],
    ]],
]);
