<?php

use Illuminate\Support\Composer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\Console\Actions\InstallModuleAction;
use Studeo\Modular\Console\Actions\UninstallModuleAction;
use Studeo\Modular\DataObjects\InstallResult;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->composerPath = base_path('composer.json');
    $this->originalContent = File::exists($this->composerPath)
        ? File::get($this->composerPath)
        : null;

    $this->rootModulesPath = config('modular.root_path');
    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);

    $this->accountModule = new ModuleDefinition(
        name: 'Account',
        namespace: 'Studeo\\Account',
        path: $this->rootModulesPath.'/Account',
    );

    File::put($this->composerPath, json_encode([
        'name' => 'laravel/laravel',
        'autoload' => [
            'psr-4' => [
                'App\\' => 'app/',
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);

    File::put($this->composerPath, json_encode([
        'name' => 'laravel/laravel',
        'autoload' => [
            'psr-4' => [
                'App\\' => 'app/',
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
});

it('asks the Filesystem for the same calls in the same order during install', function () {
    app(CreateModuleAction::class)->execute($this->accountModule);

    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')->once()->andReturn(0);
    $this->app->instance(Composer::class, $composerMock);

    $files = orderedFilesystem();
    $action = app(InstallModuleAction::class);
    $result = $action->execute($this->accountModule);

    expect($result->hasInstalled())->toBeTrue()
        ->and($files->calls)->toBe(callFixture('install-account', 'install-calls'));
});

it('asks the Filesystem for the same calls in the same order during uninstall', function () {
    app(CreateModuleAction::class)->execute($this->accountModule);

    $composer = json_decode(File::get($this->composerPath), true);
    $composer['autoload']['psr-4']['Studeo\\Account\\'] = 'modules/Account/';
    $composer['autoload']['psr-4']['Studeo\\Account\\Database\\Factories\\'] = 'modules/Account/database/factories/';
    $composer['autoload']['psr-4']['Studeo\\Account\\Database\\Seeders\\'] = 'modules/Account/database/seeders/';
    File::put($this->composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')->once()->andReturn(0);
    $this->app->instance(Composer::class, $composerMock);

    $files = orderedFilesystem();
    $action = app(UninstallModuleAction::class);
    $uninstalled = $action->execute($this->accountModule);

    expect($uninstalled)->toBeTrue()
        ->and($files->calls)->toBe(callFixture('uninstall-account', 'install-calls'));
});

it('returns empty result when no modules are passed to install', function () {
    $action = app(InstallModuleAction::class);
    $result = $action->execute();

    expect($result)->toBeInstanceOf(InstallResult::class)
        ->and($result->installed)->toBe([])
        ->and($result->alreadyInstalled)->toBe([])
        ->and($result->pending)->toBe([])
        ->and($result->hasInstalled())->toBeFalse()
        ->and($action->isRootComposerUpdated())->toBeFalse();
});

it('initializes missing psr-4 autoload key during install', function () {
    app(CreateModuleAction::class)->execute($this->accountModule);
    File::put($this->composerPath, json_encode(['name' => 'laravel/laravel']).PHP_EOL);

    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')->once()->andReturn(0);
    $this->app->instance(Composer::class, $composerMock);

    $action = app(InstallModuleAction::class);
    $result = $action->execute($this->accountModule);

    expect($result->installed)->toBe(['Account']);

    $composer = json_decode(File::get($this->composerPath), true);
    expect($composer['autoload']['psr-4'])->toHaveKey('Studeo\\Account\\');
});

it('handles missing or non-array psr-4 autoload key during uninstall', function () {
    File::put($this->composerPath, json_encode([
        'name' => 'laravel/laravel',
        'autoload' => ['psr-4' => 'not-an-array'],
    ]).PHP_EOL);

    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')->never();
    $this->app->instance(Composer::class, $composerMock);

    $action = app(UninstallModuleAction::class);
    $uninstalled = $action->execute($this->accountModule);

    expect($uninstalled)->toBeFalse();
});

it('uninstalls module by path when manifest is broken', function () {
    app(CreateModuleAction::class)->execute($this->accountModule);
    File::put($this->accountModule->path.'/module.json', '{invalid json}');

    $composer = json_decode(File::get($this->composerPath), true);
    $composer['autoload']['psr-4']['Studeo\\Account\\'] = 'modules/Account/';
    File::put($this->composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')->once()->andReturn(0);
    $this->app->instance(Composer::class, $composerMock);

    $action = app(UninstallModuleAction::class);
    $uninstalled = $action->execute($this->accountModule);

    expect($uninstalled)->toBeTrue();

    $after = json_decode(File::get($this->composerPath), true);
    expect($after['autoload']['psr-4'])->not->toHaveKey('Studeo\\Account\\');
});

it('removes matching sub-namespaces but preserves unrelated similar prefixes', function () {
    $composer = json_decode(File::get($this->composerPath), true);
    $composer['autoload']['psr-4']['Studeo\\Account\\'] = 'modules/Account/';
    $composer['autoload']['psr-4']['Studeo\\Account\\Database\\Factories\\'] = 'modules/Account/database/factories/';
    $composer['autoload']['psr-4']['Studeo\\Accountant\\'] = 'modules/Accountant/';
    File::put($this->composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')->once()->andReturn(0);
    $this->app->instance(Composer::class, $composerMock);

    $action = app(UninstallModuleAction::class);
    $uninstalled = $action->execute($this->accountModule);

    expect($uninstalled)->toBeTrue();

    $after = json_decode(File::get($this->composerPath), true);
    expect($after['autoload']['psr-4'])->not->toHaveKey('Studeo\\Account\\')
        ->and($after['autoload']['psr-4'])->not->toHaveKey('Studeo\\Account\\Database\\Factories\\')
        ->and($after['autoload']['psr-4'])->toHaveKey('Studeo\\Accountant\\');
});

it('prints notices and messages in exact order during install and uninstall', function () {
    app(CreateModuleAction::class)->execute($this->accountModule);
    File::ensureDirectoryExists($this->rootModulesPath.'/Empty');

    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')->twice()->andReturn(0);
    $this->app->instance(Composer::class, $composerMock);

    Artisan::call('module:install', ['--all' => true]);
    $installed = Artisan::output();

    expect(strpos($installed, 'Skipped [Empty]: no module.json.'))->toBeInt()
        ->and(strpos($installed, 'Module [Account] installed successfully.'))->toBeGreaterThan(strpos($installed, 'Skipped [Empty]: no module.json.'));

    expect(Artisan::call('module:uninstall', ['module' => 'Account']))->toBe(0)
        ->and(Artisan::output())->toContain('Module [Account] uninstalled successfully.');
});

it('preserves array-valued psr-4 entries during uninstall without type errors', function () {
    app(CreateModuleAction::class)->execute($this->accountModule);

    $composer = json_decode(File::get($this->composerPath), true);
    $composer['autoload']['psr-4']['App\\'] = 'app/';
    $composer['autoload']['psr-4']['Vendor\\Package\\'] = ['src/', 'lib/'];
    $composer['autoload']['psr-4']['Studeo\\Account\\'] = 'modules/Account/';
    File::put($this->composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')->once()->andReturn(0);
    $this->app->instance(Composer::class, $composerMock);

    $action = app(UninstallModuleAction::class);
    $uninstalled = $action->execute($this->accountModule);

    expect($uninstalled)->toBeTrue();

    $after = json_decode(File::get($this->composerPath), true);
    expect($after['autoload']['psr-4'])->toHaveKey('App\\')
        ->and($after['autoload']['psr-4']['Vendor\\Package\\'])->toBe(['src/', 'lib/'])
        ->and($after['autoload']['psr-4'])->not->toHaveKey('Studeo\\Account\\');
});
