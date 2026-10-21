<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\DiscoverModulesAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->rootPath = config('modular.root_path');
    File::deleteDirectory($this->rootPath);
});

afterEach(function () {
    File::deleteDirectory($this->rootPath);
});

test('it returns an empty array when root path does not exist', function () {
    $modules = app(DiscoverModulesAction::class)->execute();

    expect($modules)->toBe([]);
});

test('it returns an empty array when root path has no subdirectories', function () {
    File::ensureDirectoryExists($this->rootPath);

    $modules = app(DiscoverModulesAction::class)->execute();

    expect($modules)->toBe([]);
});

test('it ignores loose files directly in root path', function () {
    File::ensureDirectoryExists($this->rootPath);
    File::put($this->rootPath.'/.DS_Store', '');
    File::put($this->rootPath.'/README.md', '# Modules');

    $modules = app(DiscoverModulesAction::class)->execute();

    expect($modules)->toBe([]);
});

test('it ignores directories that do not contain module.json', function () {
    File::ensureDirectoryExists($this->rootPath.'/Account');
    $this->writeModuleManifest($this->rootPath.'/Billing', 'Billing', 'Studeo\\Billing');

    expect(array_keys(app(DiscoverModulesAction::class)->execute()))->toBe(['Billing']);
});

test('it discovers module directories and returns canonical ModuleDefinitions', function () {
    $this->writeModuleManifest($this->rootPath.'/Account', 'Account', 'Studeo\\Account');
    $this->writeModuleManifest($this->rootPath.'/Billing', 'Billing', 'Studeo\\Billing');

    $modules = app(DiscoverModulesAction::class)->execute();

    expect($modules)->toHaveCount(2)
        ->and(array_keys($modules))->toBe(['Account', 'Billing'])
        ->and($modules['Account'])->toBeInstanceOf(ModuleDefinition::class)
        ->and($modules['Account']->name)->toBe('Account')
        ->and($modules['Account']->namespace)->toBe('Studeo\\Account')
        ->and($modules['Account']->path)->toBe($this->rootPath.'/Account')
        ->and($modules['Billing'])->toBeInstanceOf(ModuleDefinition::class)
        ->and($modules['Billing']->name)->toBe('Billing')
        ->and($modules['Billing']->namespace)->toBe('Studeo\\Billing')
        ->and($modules['Billing']->path)->toBe($this->rootPath.'/Billing');
});

test('it reads the namespace from the manifest, not the configured root namespace', function () {
    config()->set('modular.root_namespace', null);

    $this->writeModuleManifest($this->rootPath.'/Account', 'Account', 'Account');

    $modules = app(DiscoverModulesAction::class)->execute();

    expect($modules)->toHaveKey('Account')
        ->and($modules['Account']->namespace)->toBe('Account');
});

test('it lists skipped directories with the reason', function () {
    File::ensureDirectoryExists($this->rootPath.'/Empty');
    File::ensureDirectoryExists($this->rootPath.'/Legacy');
    File::put($this->rootPath.'/Legacy/composer.json', '{}');
    $this->writeModuleManifest($this->rootPath.'/Account', 'Account', 'Studeo\\Account');

    $skipped = app(DiscoverModulesAction::class)->skipped();

    expect(array_keys($skipped))->toBe(['Empty', 'Legacy'])
        ->and($skipped['Empty'])->toBe('no module.json.')
        ->and($skipped['Legacy'])->toContain('legacy composer.json found')
        ->and($skipped['Legacy'])->toContain('module:install Legacy');
});

test('it lists no skipped directories when the root path does not exist', function () {
    File::deleteDirectory($this->rootPath);

    expect(app(DiscoverModulesAction::class)->skipped())->toBe([]);
});

test('it lists a directory with an invalid module.json as skipped, with the reason', function () {
    $this->writeRawModuleManifest($this->rootPath.'/Broken', '{not json');
    $this->writeRawModuleManifest($this->rootPath.'/Renamed', '{"name":"Other","namespace":"Studeo\\\\Renamed"}');
    $this->writeModuleManifest($this->rootPath.'/Account', 'Account', 'Studeo\\Account');

    $action = app(DiscoverModulesAction::class);
    $skipped = $action->skipped();

    expect(array_keys($action->execute()))->toBe(['Account'])
        ->and(array_keys($skipped))->toBe(['Broken', 'Renamed'])
        ->and($skipped['Broken'])->toStartWith('invalid module.json: ')->toContain('Broken')
        ->and($skipped['Renamed'])->toStartWith('invalid module.json: ')->toContain('name must be the directory name [Renamed]');
});

test('it keeps legacy directories skipped beside invalid manifests and leaves valid modules alone', function () {
    File::ensureDirectoryExists($this->rootPath.'/Legacy');
    File::put($this->rootPath.'/Legacy/composer.json', '{}');
    $this->writeRawModuleManifest($this->rootPath.'/Broken', '[]');
    $this->writeRawModuleManifest($this->rootPath.'/Bad Name', '{"name":"Bad Name","namespace":"Studeo\\\\Bad"}');
    $this->writeModuleManifest($this->rootPath.'/Account', 'Account', 'Studeo\\Account');
    $this->writeModuleManifest($this->rootPath.'/Billing', 'Billing', 'Studeo\\Billing');

    $action = app(DiscoverModulesAction::class);

    expect(array_keys($action->execute()))->toBe(['Account', 'Billing'])
        ->and(array_keys($action->skipped()))->toBe(['Bad Name', 'Broken', 'Legacy'])
        ->and($action->skipped()['Legacy'])->toContain('legacy composer.json found')
        ->and($action->skipped()['Bad Name'])->toStartWith('invalid module name: ');
});
