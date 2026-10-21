<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\Console\Actions\ResolveInstalledNamespaceAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->composerPath = base_path('composer.json');
    $this->originalContent = File::exists($this->composerPath) ? File::get($this->composerPath) : null;

    $this->rootModulesPath = config('modular.root_path');
    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);

    $this->module = new ModuleDefinition('Account', 'Studeo\\Account', $this->rootModulesPath.'/Account');
    app(CreateModuleAction::class)->execute($this->module);

    $this->putRootPsr4 = function (array $psr4) {
        File::put($this->composerPath, json_encode([
            'name' => 'laravel/laravel',
            'autoload' => ['psr-4' => ['App\\' => 'app/', ...$psr4]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    };
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);

    $this->originalContent !== null
        ? File::put($this->composerPath, $this->originalContent)
        : File::delete($this->composerPath);
});

test('it returns the manifest namespace when it is mapped in the root composer.json', function () {
    ($this->putRootPsr4)(['Studeo\\Account\\' => 'modules/Account/']);

    expect(app(ResolveInstalledNamespaceAction::class)->execute($this->module))->toBe('Studeo\\Account\\');
});

test('it ignores modular.root_namespace', function () {
    ($this->putRootPsr4)(['Studeo\\Account\\' => 'modules/Account/']);
    config()->set('modular.root_namespace', 'Renamed');

    $module = new ModuleDefinition('Account', 'Renamed\\Account', $this->module->path);

    expect(app(ResolveInstalledNamespaceAction::class)->execute($module))->toBe('Studeo\\Account\\');
});

test('it falls back to the root entry whose path is the module directory when the manifest namespace is not mapped', function () {
    ($this->putRootPsr4)(['Edited\\Elsewhere\\' => 'modules/Account/']);

    expect(app(ResolveInstalledNamespaceAction::class)->execute($this->module))->toBe('Edited\\Elsewhere\\');
});

test('it falls back to the root entry when the manifest is invalid', function () {
    $this->writeRawModuleManifest($this->module->path, '{ broken');
    ($this->putRootPsr4)(['Studeo\\Account\\' => 'modules/Account/']);

    expect(app(ResolveInstalledNamespaceAction::class)->execute($this->module))->toBe('Studeo\\Account\\');
});

test('it does not match the factories or seeders sub-path', function () {
    ($this->putRootPsr4)(['Studeo\\Account\\Database\\Factories\\' => 'modules/Account/database/factories/']);

    expect(app(ResolveInstalledNamespaceAction::class)->execute($this->module))->toBeNull();
});

test('it returns null when the module is not installed', function () {
    ($this->putRootPsr4)([]);

    expect(app(ResolveInstalledNamespaceAction::class)->execute($this->module))->toBeNull();
});
