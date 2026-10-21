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

it('resolves a Vue component name', function (string $command, array $input, array $answers, bool $interactive, array $expected) {
    expect(($this->drive)($command, $input, $answers, $interactive))->toBe($expected);
})->with([
    'given' => ['module:vue', ['name' => 'PostCard', '--module' => 'Blog'], [], true, [
        'status' => 0,
        'stdout' => implode("\n", [
            'Vue Component [PostCard] created successfully in module [Blog].',
            'Created files:',
            '  - <module>/resources/js/Components/PostCard.vue',
        ])."\n",
        'stderr' => '',
        'answered' => 0,
        'resolved' => [],
        'processes' => [],
    ]],
    'blank, answered' => ['module:vue', ['name' => '', '--module' => 'Blog'], ['PostCard'], true, [
        'status' => 0,
        'stdout' => implode("\n", [
            '',
            '  What is the name of the Vue component?',
            '❯ ',
            'Vue Component [PostCard] created successfully in module [Blog].',
            'Created files:',
            '  - <module>/resources/js/Components/PostCard.vue',
        ])."\n",
        'stderr' => '',
        'answered' => 9,
        'resolved' => [],
        'processes' => [],
    ]],
    'blank, no terminal' => ['module:vue', ['name' => '', '--module' => 'Blog'], [], false, [
        'status' => 1,
        'stdout' => "The [name] argument must be given when the command is not interactive, e.g. module:vue PostCard --module=Blog.\n",
        'stderr' => '',
        'answered' => 0,
        'resolved' => [],
        'processes' => [],
    ]],
    'whitespace, answered' => ['module:vue', ['name' => '   ', '--module' => 'Blog'], ['PostCard'], true, [
        'status' => 0,
        'stdout' => implode("\n", [
            '',
            '  What is the name of the Vue component?',
            '❯ ',
            'Vue Component [PostCard] created successfully in module [Blog].',
            'Created files:',
            '  - <module>/resources/js/Components/PostCard.vue',
        ])."\n",
        'stderr' => '',
        'answered' => 9,
        'resolved' => [],
        'processes' => [],
    ]],
    'whitespace, no terminal' => ['module:vue', ['name' => '   ', '--module' => 'Blog'], [], false, [
        'status' => 1,
        'stdout' => "The [name] argument must be given when the command is not interactive, e.g. module:vue PostCard --module=Blog.\n",
        'stderr' => '',
        'answered' => 0,
        'resolved' => [],
        'processes' => [],
    ]],
    'zero' => ['module:vue', ['name' => '0', '--module' => 'Blog'], ['Other'], true, [
        'status' => 0,
        'stdout' => implode("\n", [
            'Vue Component [0] created successfully in module [Blog].',
            'Created files:',
            '  - <module>/resources/js/Components/0.vue',
        ])."\n",
        'stderr' => '',
        'answered' => 0,
        'resolved' => [],
        'processes' => [],
    ]],
    'missing, answered' => ['module:vue', ['--module' => 'Blog'], ['PostCard'], true, [
        'status' => 0,
        'stdout' => implode("\n", [
            '',
            '  What is the name of the Vue component?',
            '❯ ',
            '  What type of Vue component would you like to create? [Embedded UI Component (Components/)]',
            '  Embedded UI Component (Components/) .............................. component  ',
            '  Inertia Full Page (Pages/) ............................................ page  ',
            '  Inertia Form Page with useForm() (Pages/) ............................. form  ',
            '❯ Vue Component [PostCard] created successfully in module [Blog].',
            'Created files:',
            '  - <module>/resources/js/Components/PostCard.vue',
        ])."\n",
        'stderr' => '',
        'answered' => 9,
        'resolved' => [],
        'processes' => [],
    ]],
    'missing, no terminal' => ['module:vue', ['--module' => 'Blog'], [], false, [
        'status' => 1,
        'stdout' => "The [name] argument must be given when the command is not interactive, e.g. module:vue PostCard --module=Blog.\n",
        'stderr' => '',
        'answered' => 0,
        'resolved' => [],
        'processes' => [],
    ]],
]);
