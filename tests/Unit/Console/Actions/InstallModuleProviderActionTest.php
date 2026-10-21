<?php

use Illuminate\Support\Composer;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\Console\Actions\InstallModuleAction;
use Studeo\Modular\Exceptions\LegacyModuleProviderException;
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

test('it throws when module service provider file is missing', function () {
    $this->writeModuleManifest($this->accountModule->path, 'Account', 'Studeo\\Account');

    $action = app(InstallModuleAction::class);

    expect(fn () => $action->execute($this->accountModule))
        ->toThrow(RuntimeException::class, "Module service provider file not found: {$this->accountModule->path}/AccountServiceProvider.php");

    expect($action->isRootComposerUpdated())->toBeFalse();
});

test('it throws when module service provider does not extend ServiceProvider', function () {
    $this->writeModuleManifest($this->accountModule->path, 'Account', 'Studeo\\Account');
    File::put(
        $this->accountModule->path.'/AccountServiceProvider.php',
        "<?php\n\nnamespace Studeo\\Account;\n\nuse Illuminate\\Support\\ServiceProvider;\n\nclass AccountServiceProvider\n{\n}\n"
    );

    $action = app(InstallModuleAction::class);

    expect(fn () => $action->execute($this->accountModule))
        ->toThrow(RuntimeException::class, 'Module service provider [AccountServiceProvider] must extend Illuminate\\Support\\ServiceProvider:');

    expect($action->isRootComposerUpdated())->toBeFalse();
});

test('it throws when module service provider does not import Laravel ServiceProvider', function () {
    $this->writeModuleManifest($this->accountModule->path, 'Account', 'Studeo\\Account');
    File::put(
        $this->accountModule->path.'/AccountServiceProvider.php',
        "<?php\n\nnamespace Studeo\\Account;\n\nclass AccountServiceProvider extends ServiceProvider\n{\n}\n"
    );

    $action = app(InstallModuleAction::class);

    expect(fn () => $action->execute($this->accountModule))
        ->toThrow(RuntimeException::class, 'Module service provider [AccountServiceProvider] must extend Illuminate\\Support\\ServiceProvider:');

    expect($action->isRootComposerUpdated())->toBeFalse();
});

test('it rolls back root composer.json and resets state flag if dumpAutoloads fails', function () {
    app(CreateModuleAction::class)->execute($this->accountModule);
    $originalContent = File::get($this->composerPath);

    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')
        ->once()
        ->andReturn(1);
    $this->app->instance(Composer::class, $composerMock);

    $action = app(InstallModuleAction::class);

    expect(fn () => $action->execute($this->accountModule))
        ->toThrow(RuntimeException::class, 'Composer dump-autoload failed with exit code [1].');

    expect(File::get($this->composerPath))->toBe($originalContent)
        ->and($action->isRootComposerUpdated())->toBeFalse();
});

test('it rolls back root composer.json and resets state flag if dumpAutoloads throws an exception', function () {
    app(CreateModuleAction::class)->execute($this->accountModule);
    $originalContent = File::get($this->composerPath);

    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')
        ->once()
        ->andThrow(new RuntimeException('Process execution failed'));
    $this->app->instance(Composer::class, $composerMock);

    $action = app(InstallModuleAction::class);

    expect(fn () => $action->execute($this->accountModule))
        ->toThrow(RuntimeException::class, 'Process execution failed');

    expect(File::get($this->composerPath))->toBe($originalContent)
        ->and($action->isRootComposerUpdated())->toBeFalse();
});

test('it refuses to install a module whose provider does not use ModuleRegistrar', function () {
    app(CreateModuleAction::class)->execute($this->accountModule);
    File::put(
        $this->accountModule->path.'/AccountServiceProvider.php',
        "<?php\n\nnamespace Studeo\\Account;\n\nuse Illuminate\\Support\\ServiceProvider;\n\nclass AccountServiceProvider extends ServiceProvider\n{\n}\n"
    );

    $action = app(InstallModuleAction::class);

    expect(fn () => $action->execute($this->accountModule))
        ->toThrow(LegacyModuleProviderException::class, 'does not use ModuleRegistrar');

    expect($action->isRootComposerUpdated())->toBeFalse();
});
