<?php

use Illuminate\Support\Composer;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\Console\Actions\InstallModuleAction;
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

    $this->billingModule = new ModuleDefinition(
        name: 'Billing',
        namespace: 'Studeo\\Billing',
        path: $this->rootModulesPath.'/Billing',
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

it('handles already-installed modules without altering composer or dumping autoloads', function () {
    app(CreateModuleAction::class)->execute($this->accountModule);
    $composer = json_decode(File::get($this->composerPath), true);
    $composer['autoload']['psr-4']['Studeo\\Account\\'] = 'modules/Account/';
    File::put($this->composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    $before = File::get($this->composerPath);

    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')->never();
    $this->app->instance(Composer::class, $composerMock);

    $result = app(InstallModuleAction::class)->execute($this->accountModule);

    expect($result->installed)->toBe([])
        ->and($result->alreadyInstalled)->toBe(['Account'])
        ->and($result->hasInstalled())->toBeFalse()
        ->and(File::get($this->composerPath))->toBe($before);
});

it('partitions already-installed and pending modules during bulk install', function () {
    app(CreateModuleAction::class)->execute($this->accountModule);
    app(CreateModuleAction::class)->execute($this->billingModule);

    $composer = json_decode(File::get($this->composerPath), true);
    $composer['autoload']['psr-4']['Studeo\\Account\\'] = 'modules/Account/';
    File::put($this->composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')->once()->andReturn(0);
    $this->app->instance(Composer::class, $composerMock);

    $result = app(InstallModuleAction::class)->execute($this->accountModule, $this->billingModule);

    expect($result->installed)->toBe(['Billing'])
        ->and($result->alreadyInstalled)->toBe(['Account']);

    $after = json_decode(File::get($this->composerPath), true);
    expect($after['autoload']['psr-4'])->toHaveKey('Studeo\\Billing\\');
});

it('throws when module directory does not exist', function () {
    expect(fn () => app(InstallModuleAction::class)->execute($this->accountModule))
        ->toThrow(RuntimeException::class, "Module [Account] not found at [{$this->accountModule->path}].");
});

it('validates provider imports, extends patterns, and aliased imports', function () {
    $action = app(InstallModuleAction::class);
    mkdir($this->accountModule->path, 0755, true);
    File::put($this->accountModule->path.'/module.json', json_encode(['name' => 'Account', 'namespace' => 'Studeo\\Account']));

    File::put($this->accountModule->path.'/AccountServiceProvider.php', "<?php\nnamespace Studeo\\Account;\nclass AccountServiceProvider extends ServiceProvider {}\n");
    expect(fn () => $action->execute($this->accountModule))
        ->toThrow(RuntimeException::class, 'must extend Illuminate\\Support\\ServiceProvider');

    File::put($this->accountModule->path.'/AccountServiceProvider.php', "<?php\nnamespace Studeo\\Account;\nuse Illuminate\\Support\\ServiceProvider;\nclass AccountServiceProvider {}\n");
    expect(fn () => $action->execute($this->accountModule))
        ->toThrow(RuntimeException::class, 'must extend Illuminate\\Support\\ServiceProvider');

    File::put($this->accountModule->path.'/AccountServiceProvider.php', "<?php\nnamespace Studeo\\Account;\nuse Illuminate\\Support\\ServiceProvider as Base;\nclass AccountServiceProvider extends Base {}\n");
    expect(fn () => $action->execute($this->accountModule))
        ->toThrow(RuntimeException::class, 'must extend Illuminate\\Support\\ServiceProvider');

    File::put($this->accountModule->path.'/AccountServiceProvider.php', "<?php\nnamespace Studeo\\Account;\nuse Studeo\\Modular\\Support\\ModuleRegistrar;\nclass AccountServiceProvider extends \\Illuminate\\Support\\ServiceProvider {\npublic function register(): void { ModuleRegistrar::for(\$this, __DIR__)->config(); }\n}\n");
    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')->once()->andReturn(0);
    $this->app->instance(Composer::class, $composerMock);
    expect(app(InstallModuleAction::class)->execute($this->accountModule)->installed)->toBe(['Account']);
});

it('rolls back composer.json byte-for-byte on dump failure or exception', function () {
    app(CreateModuleAction::class)->execute($this->accountModule);
    $original = File::get($this->composerPath);

    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')->once()->andReturn(1);
    $this->app->instance(Composer::class, $composerMock);

    expect(fn () => app(InstallModuleAction::class)->execute($this->accountModule))
        ->toThrow(RuntimeException::class, 'Composer dump-autoload failed with exit code [1].');

    expect(File::get($this->composerPath))->toBe($original);
});

it('uninstalls using manifest namespace when module exists', function () {
    app(CreateModuleAction::class)->execute($this->accountModule);
    $composer = json_decode(File::get($this->composerPath), true);
    $composer['autoload']['psr-4']['Studeo\\Account\\'] = 'modules/Account/';
    File::put($this->composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')->once()->andReturn(0);
    $this->app->instance(Composer::class, $composerMock);

    $uninstalled = app(UninstallModuleAction::class)->execute($this->accountModule);

    expect($uninstalled)->toBeTrue();
    $after = json_decode(File::get($this->composerPath), true);
    expect($after['autoload']['psr-4'])->not->toHaveKey('Studeo\\Account\\');
});

it('uninstalls using directory-path namespace when directory is missing', function () {
    $composer = json_decode(File::get($this->composerPath), true);
    $composer['autoload']['psr-4']['Studeo\\Account\\'] = 'modules/Account/';
    File::put($this->composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')->once()->andReturn(0);
    $this->app->instance(Composer::class, $composerMock);

    $uninstalled = app(UninstallModuleAction::class)->execute($this->accountModule);

    expect($uninstalled)->toBeTrue();
    $after = json_decode(File::get($this->composerPath), true);
    expect($after['autoload']['psr-4'])->not->toHaveKey('Studeo\\Account\\');
});

it('uninstalls using directory-path namespace when manifest is broken', function () {
    mkdir($this->accountModule->path, 0755, true);
    File::put($this->accountModule->path.'/module.json', '{broken json');
    $composer = json_decode(File::get($this->composerPath), true);
    $composer['autoload']['psr-4']['Studeo\\Account\\'] = 'modules/Account/';
    File::put($this->composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')->once()->andReturn(0);
    $this->app->instance(Composer::class, $composerMock);

    $uninstalled = app(UninstallModuleAction::class)->execute($this->accountModule);

    expect($uninstalled)->toBeTrue();
    $after = json_decode(File::get($this->composerPath), true);
    expect($after['autoload']['psr-4'])->not->toHaveKey('Studeo\\Account\\');
});

it('asserts message order for install and uninstall commands', function () {
    app(CreateModuleAction::class)->execute($this->accountModule);

    $composerMock = Mockery::mock(Composer::class);
    $composerMock->shouldReceive('dumpAutoloads')->twice()->andReturn(0);
    $this->app->instance(Composer::class, $composerMock);

    $this->artisan('module:install', ['module' => 'Account'])
        ->expectsOutput('Module [Account] installed successfully.')
        ->assertSuccessful();

    $this->artisan('module:uninstall', ['module' => 'Account'])
        ->expectsOutput('Module [Account] uninstalled successfully.')
        ->assertSuccessful();
});
