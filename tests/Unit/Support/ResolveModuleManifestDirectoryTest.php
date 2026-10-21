<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Exceptions\InvalidModuleManifestException;
use Studeo\Modular\Support\ResolveModuleManifestAction;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->directory = config('modular.root_path').'/Account';
    File::deleteDirectory(config('modular.root_path'));
    File::ensureDirectoryExists($this->directory);
});

afterEach(function () {
    File::deleteDirectory(config('modular.root_path'));
});

it('reads the manifest of a directory without a module definition', function () {
    $this->writeModuleManifest($this->directory, 'Account', 'Studeo\\Account');

    $manifest = app(ResolveModuleManifestAction::class)->forDirectory($this->directory);

    expect([$manifest->name, $manifest->namespace])->toBe(['Account', 'Studeo\\Account']);
});

it('names the missing manifest file when the directory has none', function () {
    expect(fn () => app(ResolveModuleManifestAction::class)->forDirectory($this->directory))
        ->toThrow(InvalidModuleManifestException::class, 'module.json');
});

it('only asks whether the manifest file exists', function () {
    $action = app(ResolveModuleManifestAction::class);

    expect($action->hasManifest($this->directory))->toBeFalse();

    File::put($this->directory.'/composer.json', '{}');
    expect($action->hasManifest($this->directory))->toBeFalse();

    File::put($this->directory.'/module.json', 'not json');
    expect($action->hasManifest($this->directory))->toBeTrue();
});
