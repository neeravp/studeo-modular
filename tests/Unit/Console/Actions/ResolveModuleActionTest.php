<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\ResolveModuleAction;
use Studeo\Modular\Exceptions\InvalidModuleManifestException;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

afterEach(function () {
    File::deleteDirectory(config('modular.root_path'));
});

test('it resolves a simple module name', function () {
    $module = app(ResolveModuleAction::class)->execute('Account');

    expect($module->name)->toBe('Account');
});

test('it refuses a prefixed module name and points at --root-namespace', function () {
    app(ResolveModuleAction::class)->execute('Studeo\\Account');
})->throws(InvalidArgumentException::class, '--root-namespace=Studeo');

test('it builds the namespace of a module without a manifest from the root namespace', function (?string $configured, string $expected) {
    config(['modular.root_namespace' => $configured]);

    expect(app(ResolveModuleAction::class)->execute('Account')->namespace)->toBe($expected);
})->with([
    'a prefix' => ['Studeo', 'Studeo\\Account'],
    'several segments' => ['Acme\\Platform', 'Acme\\Platform\\Account'],
    'none' => [null, 'Account'],
]);

test('it reads the namespace of an existing module from its manifest, whatever the config says', function (?string $configured) {
    $this->writeModuleManifest(config('modular.root_path').'/Account', 'Account', 'Acme\\Account');
    config(['modular.root_namespace' => $configured]);

    expect(app(ResolveModuleAction::class)->execute('Account')->namespace)->toBe('Acme\\Account');
})->with(['another prefix' => ['Studeo'], 'none' => [null]]);

test('it reports a manifest that cannot be used instead of falling back to the config', function () {
    $this->writeModuleManifest(config('modular.root_path').'/Account', 'Account', 'Illuminate\\Account');

    app(ResolveModuleAction::class)->execute('Account');
})->throws(InvalidModuleManifestException::class, '[Illuminate]');

test('a new module takes the given root namespace over the config, and an empty one means none', function () {
    config(['modular.root_namespace' => 'Studeo']);
    $resolver = app(ResolveModuleAction::class);

    expect($resolver->forNewModule('Account', 'Acme')->namespace)->toBe('Acme\\Account')
        ->and($resolver->forNewModule('Account', '')->namespace)->toBe('Account')
        ->and($resolver->forNewModule('Account')->namespace)->toBe('Studeo\\Account');
});

test('it refuses a configured root namespace that breaks the rules', function () {
    config(['modular.root_namespace' => 'App']);

    app(ResolveModuleAction::class)->execute('Account');
})->throws(InvalidArgumentException::class, '[App]');

test('it resolves the module path using the configured root path', function () {
    config(['modular.root_path' => base_path('domains')]);

    expect(app(ResolveModuleAction::class)->execute('Account')->path)->toBe(base_path('domains/Account'));
});
