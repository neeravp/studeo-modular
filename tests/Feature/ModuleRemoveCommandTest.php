<?php

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

    // Initial clean root composer without Account
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

it('refuses to remove an installed module and fails immediately', function () {
    app(CreateModuleAction::class)->execute($this->accountModule);

    // Register Account in root composer
    $composer = json_decode(File::get($this->composerPath), true);
    $composer['autoload']['psr-4']['Studeo\\Account\\'] = 'modules/Account/';
    File::put($this->composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

    $this->artisan('module:remove', [
        'module' => 'Account',
    ])
        ->expectsOutput('Cannot remove module [Account] because it is currently installed. Run [php artisan module:uninstall Account] first.')
        ->assertFailed();

    expect($this->accountModule->path)->toBeDirectory();
});

it('prompts for confirmation and aborts when confirmation is declined', function () {
    app(CreateModuleAction::class)->execute($this->accountModule);

    $this->artisan('module:remove', [
        'module' => 'Account',
    ])
        ->expectsConfirmation('Are you sure you want to delete module [Account]?', 'no')
        ->expectsOutput('Module [Account] was not removed.')
        ->assertSuccessful();

    expect($this->accountModule->path)->toBeDirectory();
});

it('prompts for confirmation and removes module when confirmed', function () {
    app(CreateModuleAction::class)->execute($this->accountModule);

    $this->artisan('module:remove', [
        'module' => 'Account',
    ])
        ->expectsConfirmation('Are you sure you want to delete module [Account]?', 'yes')
        ->expectsOutput('Module [Account] removed successfully.')
        ->assertSuccessful();

    expect($this->accountModule->path)->not->toBeDirectory();
});

it('removes module immediately without confirmation when --force is supplied', function () {
    app(CreateModuleAction::class)->execute($this->accountModule);

    $this->artisan('module:remove', [
        'module' => 'Account',
        '--force' => true,
    ])
        ->expectsOutput('Module [Account] removed successfully.')
        ->assertSuccessful();

    expect($this->accountModule->path)->not->toBeDirectory();
});

it('fails when module directory does not exist on disk', function () {
    $this->artisan('module:remove', [
        'module' => 'Account',
        '--force' => true,
    ])
        ->expectsOutput("Module [Account] not found at [{$this->accountModule->path}].")
        ->assertFailed();
});
