<?php

use Illuminate\Support\Composer;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\Console\Actions\UninstallModuleAction;
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

    // Initial root composer with App and Account installed
    File::put($this->composerPath, json_encode([
        'name' => 'laravel/laravel',
        'autoload' => [
            'psr-4' => [
                'App\\' => 'app/',
                'Studeo\\Account\\' => 'modules/Account/',
                'Studeo\\Account\\Database\\Factories\\' => 'modules/Account/database/factories/',
                'Studeo\\Account\\Database\\Seeders\\' => 'modules/Account/database/seeders/',
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);

    if ($this->originalContent !== null) {
        File::put($this->composerPath, $this->originalContent);
    } else {
        File::delete($this->composerPath);
    }
});

test('it uninstalls an installed module, removes its psr-4 entry, and dumps autoloads', function () {
    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')
        ->once()
        ->andReturn(0);
    $this->app->instance(Composer::class, $composerMock);

    $action = app(UninstallModuleAction::class);
    $uninstalled = $action->execute($this->accountModule);

    expect($uninstalled)->toBeTrue();

    $composer = json_decode(File::get($this->composerPath), true);
    expect($composer['autoload']['psr-4'])->toHaveKey('App\\')
        ->and($composer['autoload']['psr-4'])->not->toHaveKey('Studeo\\Account\\')
        ->and($composer['autoload']['psr-4'])->not->toHaveKey('Studeo\\Account\\Database\\Factories\\')
        ->and($composer['autoload']['psr-4'])->not->toHaveKey('Studeo\\Account\\Database\\Seeders\\');
});

test('it leaves module files on disk completely intact', function () {
    app(CreateModuleAction::class)->execute($this->accountModule);

    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')
        ->once()
        ->andReturn(0);
    $this->app->instance(Composer::class, $composerMock);

    $action = app(UninstallModuleAction::class);
    $uninstalled = $action->execute($this->accountModule);

    expect($uninstalled)->toBeTrue()
        ->and($this->accountModule->path)->toBeDirectory()
        ->and($this->accountModule->path.'/AccountServiceProvider.php')->toBeFile()
        ->and($this->accountModule->path.'/module.json')->toBeFile();
});

test('it is idempotent and returns false without dumping autoloads if module is not installed', function () {
    // Remove Account from root composer so it is not installed
    $composer = json_decode(File::get($this->composerPath), true);
    unset($composer['autoload']['psr-4']['Studeo\\Account\\']);
    File::put($this->composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    $beforeContent = File::get($this->composerPath);

    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')->never();
    $this->app->instance(Composer::class, $composerMock);

    $action = app(UninstallModuleAction::class);
    $uninstalled = $action->execute($this->accountModule);

    expect($uninstalled)->toBeFalse()
        ->and(File::get($this->composerPath))->toBe($beforeContent);
});

test('it uninstalls when module directory does not exist on disk', function () {
    // Ensure directory does not exist
    File::deleteDirectory($this->accountModule->path);

    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')
        ->once()
        ->andReturn(0);
    $this->app->instance(Composer::class, $composerMock);

    $action = app(UninstallModuleAction::class);
    $uninstalled = $action->execute($this->accountModule);

    expect($uninstalled)->toBeTrue();

    $composer = json_decode(File::get($this->composerPath), true);
    expect($composer['autoload']['psr-4'])->not->toHaveKey('Studeo\\Account\\');
});

test('it rolls back root composer.json if dumpAutoloads fails during uninstall', function () {
    $beforeContent = File::get($this->composerPath);

    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')
        ->once()
        ->andReturn(1);
    $this->app->instance(Composer::class, $composerMock);

    $action = app(UninstallModuleAction::class);

    expect(fn () => $action->execute($this->accountModule))
        ->toThrow(RuntimeException::class, 'Composer dump-autoload failed with exit code [1].');

    expect(File::get($this->composerPath))->toBe($beforeContent);
});
