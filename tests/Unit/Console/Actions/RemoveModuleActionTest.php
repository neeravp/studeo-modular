<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\Console\Actions\RemoveModuleAction;
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

test('it refuses to remove an installed module and instructs user to uninstall first', function () {
    // Scaffold module on disk
    app(CreateModuleAction::class)->execute($this->accountModule);

    // Register module in root composer.json
    $composer = json_decode(File::get($this->composerPath), true);
    $composer['autoload']['psr-4']['Studeo\\Account\\'] = 'modules/Account/';
    File::put($this->composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

    $action = app(RemoveModuleAction::class);

    expect(fn () => $action->execute($this->accountModule))
        ->toThrow(
            RuntimeException::class,
            'Cannot remove module [Account] because it is currently installed. Run [php artisan module:uninstall Account] first.'
        );

    // Ensure directory on disk was NOT deleted
    expect($this->accountModule->path)->toBeDirectory();
});

test('it throws when module directory does not exist on disk', function () {
    // Account is not in root composer, and directory does not exist
    $action = app(RemoveModuleAction::class);

    expect(fn () => $action->execute($this->accountModule))
        ->toThrow(
            RuntimeException::class,
            "Module [Account] not found at [{$this->accountModule->path}]."
        );
});

test('it permanently deletes an uninstalled module directory from disk', function () {
    // Scaffold module on disk
    app(CreateModuleAction::class)->execute($this->accountModule);
    expect($this->accountModule->path)->toBeDirectory();

    $action = app(RemoveModuleAction::class);
    $action->execute($this->accountModule);

    expect($this->accountModule->path)->not->toBeDirectory();
});
