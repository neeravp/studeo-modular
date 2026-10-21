<?php

use Illuminate\Support\Composer;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
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

it('uninstalls an installed module successfully', function () {
    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')
        ->once()
        ->andReturn(0);
    $this->app->instance(Composer::class, $composerMock);

    $this->artisan('module:uninstall', [
        'module' => 'Account',
    ])
        ->expectsOutput('Module [Account] uninstalled successfully.')
        ->assertSuccessful();

    $composer = json_decode(File::get($this->composerPath), true);
    expect($composer['autoload']['psr-4'])->toHaveKey('App\\')
        ->and($composer['autoload']['psr-4'])->not->toHaveKey('Studeo\\Account\\');
});

it('is idempotent when target module is not installed', function () {
    $composer = json_decode(File::get($this->composerPath), true);
    unset($composer['autoload']['psr-4']['Studeo\\Account\\']);
    File::put($this->composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    $beforeContent = File::get($this->composerPath);

    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')->never();
    $this->app->instance(Composer::class, $composerMock);

    $this->artisan('module:uninstall', [
        'module' => 'Account',
    ])
        ->expectsOutput('Module [Account] is not installed.')
        ->assertSuccessful();

    expect(File::get($this->composerPath))->toBe($beforeContent);
});

it('leaves module files on disk completely intact when uninstalled', function () {
    app(CreateModuleAction::class)->execute($this->accountModule);

    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')
        ->once()
        ->andReturn(0);
    $this->app->instance(Composer::class, $composerMock);

    $this->artisan('module:uninstall', [
        'module' => 'Account',
    ])
        ->expectsOutput('Module [Account] uninstalled successfully.')
        ->assertSuccessful();

    expect($this->accountModule->path)->toBeDirectory()
        ->and($this->accountModule->path.'/AccountServiceProvider.php')->toBeFile()
        ->and($this->accountModule->path.'/module.json')->toBeFile();
});

it('uninstalls when module directory does not exist on disk', function () {
    File::deleteDirectory($this->accountModule->path);

    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')
        ->once()
        ->andReturn(0);
    $this->app->instance(Composer::class, $composerMock);

    $this->artisan('module:uninstall', [
        'module' => 'Account',
    ])
        ->expectsOutput('Module [Account] uninstalled successfully.')
        ->assertSuccessful();

    $composer = json_decode(File::get($this->composerPath), true);
    expect($composer['autoload']['psr-4'])->not->toHaveKey('Studeo\\Account\\');
});

it('fails and rolls back root composer if composer dump-autoload fails', function () {
    $beforeContent = File::get($this->composerPath);

    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')
        ->once()
        ->andReturn(1);
    $this->app->instance(Composer::class, $composerMock);

    $this->artisan('module:uninstall', [
        'module' => 'Account',
    ])
        ->assertFailed();

    expect(File::get($this->composerPath))->toBe($beforeContent);
});

it('reports when no modules are installed if argument is omitted', function () {
    // Remove Account so no modules are installed
    $composer = json_decode(File::get($this->composerPath), true);
    unset($composer['autoload']['psr-4']['Studeo\\Account\\']);
    File::put($this->composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

    $this->artisan('module:uninstall')
        ->expectsOutput('No modules are currently installed.')
        ->assertSuccessful();
});

it('prompts to select module when argument is omitted and uninstalls selection', function () {
    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')
        ->once()
        ->andReturn(0);
    $this->app->instance(Composer::class, $composerMock);

    $this->artisan('module:uninstall')
        ->expectsQuestion('Which module would you like to uninstall?', 'Account')
        ->expectsOutput('Module [Account] uninstalled successfully.')
        ->assertSuccessful();

    $composer = json_decode(File::get($this->composerPath), true);
    expect($composer['autoload']['psr-4'])->not->toHaveKey('Studeo\\Account\\');
});
