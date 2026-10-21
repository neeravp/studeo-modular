<?php

use Studeo\Modular\Support\ModuleNamespace;

test('it joins a prefix and a name, and a missing or empty prefix means none', function (?string $prefix, string $expected) {
    expect(ModuleNamespace::for('Account', $prefix))->toBe($expected);
})->with([
    'null' => [null, 'Account'],
    'empty' => ['', 'Account'],
    'one segment' => ['Studeo', 'Studeo\\Account'],
    'several segments' => ['Acme\\Platform', 'Acme\\Platform\\Account'],
    'a reserved word further in' => ['Acme\\App', 'Acme\\App\\Account'],
]);

test('it refuses a prefix that is not valid namespace segments, naming the problem', function (string $prefix, string $message) {
    expect(fn () => ModuleNamespace::for('Account', $prefix))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'leading backslash' => ['\\Acme', 'must not start with a backslash'],
    'trailing backslash' => ['Acme\\', 'must not end with a backslash'],
    'empty segment' => ['Acme\\\\Platform', 'empty segment'],
    'forward slash' => ['Acme/Platform', '[Acme/Platform] is not a valid PHP identifier'],
    'segment starting with a digit' => ['Acme\\1st', '[1st] is not a valid PHP identifier'],
    'segment with a dash' => ['Acme-Co', '[Acme-Co] is not a valid PHP identifier'],
]);

test('it refuses a first segment the root app or a common vendor owns, with or without a prefix', function (string $segment, ?string $prefix) {
    $name = $prefix === null ? $segment : 'Account';

    expect(fn () => ModuleNamespace::for($name, $prefix ?? ''))
        ->toThrow(InvalidArgumentException::class, "[{$segment}]");
})->with(array_merge(...array_map(
    fn (string $segment): array => [
        "{$segment} as the module name" => [$segment, null],
        "{$segment} as the prefix" => [$segment, $segment],
    ],
    ['App', 'Database', 'Tests', 'Illuminate', 'Livewire', 'Inertia', 'Laravel', 'Symfony', 'Composer', 'PHPUnit', 'Pest']
)));

test('it compares reserved segments case-insensitively, as PHP resolves classes', function () {
    expect(fn () => ModuleNamespace::for('database', ''))->toThrow(InvalidArgumentException::class, '[database]');
});

test('it says why a reserved segment is refused', function () {
    expect(fn () => ModuleNamespace::for('Database', ''))
        ->toThrow(InvalidArgumentException::class, 'belongs to the root application or a common vendor');
});

test('a reserved name is fine under a prefix', function () {
    expect(ModuleNamespace::for('Database', 'Acme'))->toBe('Acme\\Database');
});

test('it checks a whole namespace the same way, for manifests', function (string $namespace, string $message) {
    expect(fn () => ModuleNamespace::assertValid($namespace))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'trailing backslash' => ['Acme\\Account\\', 'must not end with a backslash'],
    'reserved' => ['Illuminate\\Account', '[Illuminate]'],
    'empty segment' => ['Acme\\\\Account', 'empty segment'],
]);

test('a module name is one studly segment', function (string $name, string $message) {
    expect(fn () => ModuleNamespace::assertName($name))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'the old prefixed form' => ['Studeo\\Account', '--root-namespace=Studeo'],
    'a slash form' => ['Studeo/Account', '--root-namespace=Studeo'],
    'three segments' => ['A\\B\\C', 'one segment'],
    'lowercase first letter' => ['account', 'StudlyCase'],
    'a dash' => ['blog-posts', 'StudlyCase'],
    'blank' => ['', 'blank'],
]);
