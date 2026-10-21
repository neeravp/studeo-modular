<?php

use Illuminate\Support\Composer;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\Console\Actions\InstallModuleAction;
use Studeo\Modular\DataObjects\InstallResult;
use Studeo\Modular\Tests\Support\ModulesRootFixture;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->fixture = new ModulesRootFixture;
    $this->composerPath = $this->fixture->composerPath;
    $this->fixture->writeRoot();
    $this->rootModulesPath = $this->fixture->rootPath;
    $this->accountModule = $this->fixture->definition('Account');
    $this->billingModule = $this->fixture->definition('Billing');
});

afterEach(fn () => $this->fixture->tearDown());

test('it installs a single module successfully and updates root composer.json', function () {
    app(CreateModuleAction::class)->execute($this->accountModule);

    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')
        ->once()
        ->andReturn(0);
    $this->app->instance(Composer::class, $composerMock);

    $action = app(InstallModuleAction::class);
    $result = $action->execute($this->accountModule);

    expect($result)->toBeInstanceOf(InstallResult::class)
        ->and($result->installed)->toBe(['Account'])
        ->and($result->alreadyInstalled)->toBe([])
        ->and($result->pending)->toBe([])
        ->and($result->hasInstalled())->toBeTrue()
        ->and($action->isRootComposerUpdated())->toBeTrue();

    $composer = json_decode(File::get($this->composerPath), true);
    expect($composer['autoload']['psr-4'])->toHaveKey('Studeo\\Account\\')
        ->and($composer['autoload']['psr-4']['Studeo\\Account\\'])->toBe('modules/Account/')
        ->and($composer['autoload']['psr-4']['Studeo\\Account\\Database\\Factories\\'])->toBe('modules/Account/database/factories/')
        ->and($composer['autoload']['psr-4']['Studeo\\Account\\Database\\Seeders\\'])->toBe('modules/Account/database/seeders/');
});

test('it is idempotent and leaves root composer.json untouched if module is already installed', function () {
    app(CreateModuleAction::class)->execute($this->accountModule);

    // Pre-install Account in root composer.json
    $composerData = json_decode(File::get($this->composerPath), true);
    $composerData['autoload']['psr-4']['Studeo\\Account\\'] = 'modules/Account/';
    File::put($this->composerPath, json_encode($composerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    $beforeContent = File::get($this->composerPath);

    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')->never();
    $this->app->instance(Composer::class, $composerMock);

    $action = app(InstallModuleAction::class);
    $result = $action->execute($this->accountModule);

    expect($result->installed)->toBe([])
        ->and($result->alreadyInstalled)->toBe(['Account'])
        ->and($result->pending)->toBe([])
        ->and($result->hasInstalled())->toBeFalse()
        ->and($action->isRootComposerUpdated())->toBeFalse()
        ->and(File::get($this->composerPath))->toBe($beforeContent);
});

test('it installs multiple modules in bulk and dumps autoload once', function () {
    app(CreateModuleAction::class)->execute($this->accountModule);
    app(CreateModuleAction::class)->execute($this->billingModule);

    // Pre-install Account, leaving Billing as pending
    $composerData = json_decode(File::get($this->composerPath), true);
    $composerData['autoload']['psr-4']['Studeo\\Account\\'] = 'modules/Account/';
    File::put($this->composerPath, json_encode($composerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')
        ->once()
        ->andReturn(0);
    $this->app->instance(Composer::class, $composerMock);

    $action = app(InstallModuleAction::class);
    $result = $action->execute($this->accountModule, $this->billingModule);

    expect($result->installed)->toBe(['Billing'])
        ->and($result->alreadyInstalled)->toBe(['Account'])
        ->and($result->pending)->toBe([])
        ->and($action->isRootComposerUpdated())->toBeTrue();

    $composer = json_decode(File::get($this->composerPath), true);
    expect($composer['autoload']['psr-4']['Studeo\\Account\\'])->toBe('modules/Account/')
        ->and($composer['autoload']['psr-4']['Studeo\\Billing\\'])->toBe('modules/Billing/');
});

test('it throws when module directory does not exist', function () {
    $action = app(InstallModuleAction::class);

    expect(fn () => $action->execute($this->accountModule))
        ->toThrow(RuntimeException::class, "Module [Account] not found at [{$this->accountModule->path}].");

    expect($action->isRootComposerUpdated())->toBeFalse();
});
