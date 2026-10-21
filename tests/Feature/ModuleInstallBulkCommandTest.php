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

it('discovers and installs all pending modules in bulk mode', function () {
    app(CreateModuleAction::class)->execute($this->accountModule);
    app(CreateModuleAction::class)->execute($this->billingModule);

    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')
        ->once()
        ->andReturn(0);
    $this->app->instance(Composer::class, $composerMock);

    $this->artisan('module:install')
        ->expectsQuestion('Which module(s) would you like to install?', ['Account', 'Billing'])
        ->expectsOutput('Module [Account] installed successfully.')
        ->expectsOutput('Module [Billing] installed successfully.')
        ->assertSuccessful();

    $composer = json_decode(File::get($this->composerPath), true);
    expect($composer['autoload']['psr-4'])->toHaveKey('Studeo\\Account\\')
        ->and($composer['autoload']['psr-4']['Studeo\\Account\\'])->toBe('modules/Account/')
        ->and($composer['autoload']['psr-4'])->toHaveKey('Studeo\\Billing\\')
        ->and($composer['autoload']['psr-4']['Studeo\\Billing\\'])->toBe('modules/Billing/');
});

it('is idempotent in bulk mode when all discovered modules are already installed', function () {
    app(CreateModuleAction::class)->execute($this->accountModule);
    app(CreateModuleAction::class)->execute($this->billingModule);

    $composerData = json_decode(File::get($this->composerPath), true);
    $composerData['autoload']['psr-4']['Studeo\\Account\\'] = 'modules/Account/';
    $composerData['autoload']['psr-4']['Studeo\\Billing\\'] = 'modules/Billing/';
    File::put($this->composerPath, json_encode($composerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    $beforeContent = File::get($this->composerPath);

    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')->never();
    $this->app->instance(Composer::class, $composerMock);

    $this->artisan('module:install')
        ->expectsOutput('All modules are already installed.')
        ->assertSuccessful();

    expect(File::get($this->composerPath))->toBe($beforeContent);
});

it('installs only pending modules when some modules are already installed in bulk mode', function () {
    app(CreateModuleAction::class)->execute($this->accountModule);
    app(CreateModuleAction::class)->execute($this->billingModule);

    $composerData = json_decode(File::get($this->composerPath), true);
    $composerData['autoload']['psr-4']['Studeo\\Account\\'] = 'modules/Account/';
    File::put($this->composerPath, json_encode($composerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')
        ->once()
        ->andReturn(0);
    $this->app->instance(Composer::class, $composerMock);

    $this->artisan('module:install')
        ->expectsQuestion('Which module(s) would you like to install?', ['Billing'])
        ->expectsOutput('Module [Billing] installed successfully.')
        ->assertSuccessful();

    $composer = json_decode(File::get($this->composerPath), true);
    expect($composer['autoload']['psr-4'])->toHaveKey('Studeo\\Account\\')
        ->and($composer['autoload']['psr-4']['Studeo\\Account\\'])->toBe('modules/Account/')
        ->and($composer['autoload']['psr-4'])->toHaveKey('Studeo\\Billing\\')
        ->and($composer['autoload']['psr-4']['Studeo\\Billing\\'])->toBe('modules/Billing/');
});

it('installs all pending modules without prompting when --all is supplied', function () {
    app(CreateModuleAction::class)->execute($this->accountModule);
    app(CreateModuleAction::class)->execute($this->billingModule);

    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')
        ->once()
        ->andReturn(0);
    $this->app->instance(Composer::class, $composerMock);

    $this->artisan('module:install', ['--all' => true])
        ->expectsOutput('Module [Account] installed successfully.')
        ->expectsOutput('Module [Billing] installed successfully.')
        ->assertSuccessful();

    $composer = json_decode(File::get($this->composerPath), true);
    expect($composer['autoload']['psr-4'])->toHaveKey('Studeo\\Account\\')
        ->and($composer['autoload']['psr-4']['Studeo\\Account\\'])->toBe('modules/Account/')
        ->and($composer['autoload']['psr-4'])->toHaveKey('Studeo\\Billing\\')
        ->and($composer['autoload']['psr-4']['Studeo\\Billing\\'])->toBe('modules/Billing/');
});

it('reports when no modules are found in bulk mode', function () {
    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')->never();
    $this->app->instance(Composer::class, $composerMock);

    $this->artisan('module:install')
        ->expectsOutput("No modules found in [{$this->rootModulesPath}].")
        ->assertSuccessful();
});

it('lists skipped directories when installing all modules', function () {
    app(CreateModuleAction::class)->execute($this->accountModule);
    File::ensureDirectoryExists($this->rootModulesPath.'/Legacy');
    File::put($this->rootModulesPath.'/Legacy/composer.json', '{}');
    File::ensureDirectoryExists($this->rootModulesPath.'/Empty');

    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')->once()->andReturn(0);
    $this->app->instance(Composer::class, $composerMock);

    $this->artisan('module:install', ['--all' => true])
        ->expectsOutputToContain('Skipped [Empty]: no module.json.')
        ->expectsOutputToContain('Skipped [Legacy]: legacy composer.json found, no module.json. Run [php artisan module:install Legacy]')
        ->expectsOutput('Module [Account] installed successfully.')
        ->assertSuccessful();
});

it('lists skipped directories even when no module can be installed', function () {
    File::ensureDirectoryExists($this->rootModulesPath.'/Legacy');
    File::put($this->rootModulesPath.'/Legacy/composer.json', '{}');

    $this->artisan('module:install', ['--all' => true])
        ->expectsOutputToContain('Skipped [Legacy]')
        ->expectsOutput('No modules found in ['.$this->rootModulesPath.'].')
        ->assertSuccessful();
});
