<?php

use Illuminate\Support\Composer;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->composerPath = base_path('composer.json');
    $this->originalRootComposer = File::exists($this->composerPath)
        ? File::get($this->composerPath)
        : null;

    File::put($this->composerPath, json_encode([
        'name' => 'laravel/laravel',
        'autoload' => [
            'psr-4' => [
                'App\\' => 'app/',
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

    $this->rootModulesPath = config('modular.root_path');
    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);

    $this->accountModule = new ModuleDefinition(
        name: 'Account',
        namespace: 'Studeo\\Account',
        path: $this->rootModulesPath.'/Account',
    );

    $this->billingModule = new ModuleDefinition(
        name: 'Billing',
        namespace: 'Studeo\\Billing',
        path: $this->rootModulesPath.'/Billing',
    );
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);

    if ($this->originalRootComposer !== null) {
        File::put($this->composerPath, $this->originalRootComposer);
    } else {
        File::delete($this->composerPath);
    }
});

it('installs a single module targeted by argument', function () {
    app(CreateModuleAction::class)->execute($this->accountModule);

    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')
        ->once()
        ->andReturn(0);
    $this->app->instance(Composer::class, $composerMock);

    $this->artisan('module:install', [
        'module' => 'Account',
    ])
        ->expectsOutput('Module [Account] installed successfully.')
        ->assertSuccessful();

    $composer = json_decode(File::get($this->composerPath), true);
    expect($composer['autoload']['psr-4'])->toHaveKey('Studeo\\Account\\')
        ->and($composer['autoload']['psr-4']['Studeo\\Account\\'])->toBe('modules/Account/');
});

it('is idempotent when target module is already installed', function () {
    app(CreateModuleAction::class)->execute($this->accountModule);

    $composerData = json_decode(File::get($this->composerPath), true);
    $composerData['autoload']['psr-4']['Studeo\\Account\\'] = 'modules/Account/';
    File::put($this->composerPath, json_encode($composerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    $beforeContent = File::get($this->composerPath);

    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')->never();
    $this->app->instance(Composer::class, $composerMock);

    $this->artisan('module:install', [
        'module' => 'Account',
    ])
        ->expectsOutput('Module [Account] is already installed.')
        ->assertSuccessful();

    expect(File::get($this->composerPath))->toBe($beforeContent);
});

it('fails targeted installation when module directory does not exist', function () {
    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')->never();
    $this->app->instance(Composer::class, $composerMock);

    $this->artisan('module:install', [
        'module' => 'Account',
    ])
        ->expectsOutput("Module [Account] not found at [{$this->rootModulesPath}/Account].")
        ->assertFailed();
});

it('fails targeted installation when module validation fails', function () {
    $this->writeRawModuleManifest($this->rootModulesPath.'/Account', '{ invalid json }');

    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')->never();
    $this->app->instance(Composer::class, $composerMock);

    $this->artisan('module:install', [
        'module' => 'Account',
    ])
        ->assertFailed();
});

it('fails and rolls back root composer.json if composer dump-autoload fails', function () {
    app(CreateModuleAction::class)->execute($this->accountModule);

    $beforeContent = File::get($this->composerPath);

    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')
        ->once()
        ->andReturn(1);
    $this->app->instance(Composer::class, $composerMock);

    $this->artisan('module:install', [
        'module' => 'Account',
    ])
        ->assertFailed();

    expect(File::get($this->composerPath))->toBe($beforeContent);
});
