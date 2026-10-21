<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\Console\Actions\DiscoverInstalledModulesAction;
use Studeo\Modular\DataObjects\InstalledModule;
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

    // Initial clean root composer without modules
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

    if ($this->originalContent !== null) {
        File::put($this->composerPath, $this->originalContent);
    } else {
        File::delete($this->composerPath);
    }
});

test('it returns an empty array when root composer does not exist', function () {
    File::delete($this->composerPath);

    $modules = app(DiscoverInstalledModulesAction::class)->execute();

    expect($modules)->toBe([]);
});

test('it returns an empty array when no modules are installed in root composer', function () {
    $modules = app(DiscoverInstalledModulesAction::class)->execute();

    expect($modules)->toBe([]);
});

test('it ignores modules that exist on disk but are not installed in root composer', function () {
    app(CreateModuleAction::class)->execute($this->accountModule);

    $modules = app(DiscoverInstalledModulesAction::class)->execute();

    expect($modules)->toBe([]);
});

test('it discovers installed modules and returns InstalledModule instances', function () {
    app(CreateModuleAction::class)->execute($this->accountModule);
    app(CreateModuleAction::class)->execute($this->billingModule);

    // Register both in root composer
    $composer = json_decode(File::get($this->composerPath), true);
    $composer['autoload']['psr-4']['Studeo\\Account\\'] = 'modules/Account/';
    $composer['autoload']['psr-4']['Studeo\\Billing\\'] = 'modules/Billing/';
    File::put($this->composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

    $modules = app(DiscoverInstalledModulesAction::class)->execute();

    expect($modules)->toHaveCount(2)
        ->and(array_keys($modules))->toBe(['Account', 'Billing'])
        ->and($modules['Account'])->toBeInstanceOf(InstalledModule::class)
        ->and($modules['Account']->module->name)->toBe('Account')
        ->and($modules['Account']->manifest->provider())->toBe('Studeo\\Account\\AccountServiceProvider')
        ->and($modules['Account']->providerPath)->toBe($this->accountModule->path.'/AccountServiceProvider.php')
        ->and($modules['Billing'])->toBeInstanceOf(InstalledModule::class)
        ->and($modules['Billing']->module->name)->toBe('Billing')
        ->and($modules['Billing']->manifest->provider())->toBe('Studeo\\Billing\\BillingServiceProvider')
        ->and($modules['Billing']->providerPath)->toBe($this->billingModule->path.'/BillingServiceProvider.php');
});

test('it skips installed modules whose directories do not exist on disk', function () {
    app(CreateModuleAction::class)->execute($this->accountModule);

    // Register both in root composer, but Billing folder does not exist
    $composer = json_decode(File::get($this->composerPath), true);
    $composer['autoload']['psr-4']['Studeo\\Account\\'] = 'modules/Account/';
    $composer['autoload']['psr-4']['Studeo\\Billing\\'] = 'modules/Billing/';
    File::put($this->composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

    $modules = app(DiscoverInstalledModulesAction::class)->execute();

    expect($modules)->toHaveCount(1)
        ->and(array_keys($modules))->toBe(['Account']);
});

test('it skips installed modules whose manifest or service provider is missing', function () {
    // Create Account folder without module.json
    File::ensureDirectoryExists($this->accountModule->path);

    $composer = json_decode(File::get($this->composerPath), true);
    $composer['autoload']['psr-4']['Studeo\\Account\\'] = 'modules/Account/';
    File::put($this->composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

    $modules = app(DiscoverInstalledModulesAction::class)->execute();

    expect($modules)->toBe([]);
});

function registerInModuleRootComposer(string $composerPath, string $name): void
{
    $composer = json_decode(File::get($composerPath), true);
    $composer['autoload']['psr-4']["Studeo\\{$name}\\"] = "modules/{$name}/";
    File::put($composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
}

test('it returns a failure instead of swallowing a legacy manifest', function () {
    File::ensureDirectoryExists($this->accountModule->path);
    File::put($this->accountModule->path.'/composer.json', '{}');
    registerInModuleRootComposer($this->composerPath, 'Account');

    $discovered = app(DiscoverInstalledModulesAction::class)->discover();

    expect($discovered->installed)->toBe([])
        ->and($discovered->failures['Account']->getMessage())
        ->toContain('Module [Account] failed to load')
        ->toContain('Create '.$this->accountModule->path.'/module.json with:');
});

test('it returns a failure for an invalid manifest', function () {
    app(CreateModuleAction::class)->execute($this->accountModule);
    $this->writeRawModuleManifest($this->accountModule->path, '{ broken');
    registerInModuleRootComposer($this->composerPath, 'Account');

    $discovered = app(DiscoverInstalledModulesAction::class)->discover();

    expect($discovered->failures['Account']->getMessage())->toContain('Invalid module manifest');
});

test('it returns a failure for a mapped module whose directory is missing', function () {
    registerInModuleRootComposer($this->composerPath, 'Billing');

    $discovered = app(DiscoverInstalledModulesAction::class)->discover();

    expect($discovered->failures['Billing']->getMessage())
        ->toContain('Module [Billing] failed to load')
        ->toContain('module:uninstall Billing');
});

test('it returns a failure for a module whose service provider file is missing', function () {
    app(CreateModuleAction::class)->execute($this->accountModule);
    File::delete($this->accountModule->path.'/AccountServiceProvider.php');
    registerInModuleRootComposer($this->composerPath, 'Account');

    $discovered = app(DiscoverInstalledModulesAction::class)->discover();

    expect($discovered->failures['Account']->getMessage())->toContain('service provider file not found');
});

test('it returns a failure when the root composer.json cannot be read', function () {
    File::put($this->composerPath, '{ not json');

    $discovered = app(DiscoverInstalledModulesAction::class)->discover();

    expect($discovered->installed)->toBe([])
        ->and($discovered->failures['composer.json']->getMessage())->toContain('Installed modules could not be read');
});

test('it keeps loading healthy modules when another module fails', function () {
    app(CreateModuleAction::class)->execute($this->accountModule);
    registerInModuleRootComposer($this->composerPath, 'Account');
    registerInModuleRootComposer($this->composerPath, 'Billing');

    $discovered = app(DiscoverInstalledModulesAction::class)->discover();

    expect(array_keys($discovered->installed))->toBe(['Account'])
        ->and(array_keys($discovered->failures))->toBe(['Billing']);
});
