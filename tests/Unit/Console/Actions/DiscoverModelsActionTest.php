<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModelAction;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\Console\Actions\DiscoverModelsAction;
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

    app(CreateModuleAction::class)->execute($this->accountModule);
    app(CreateModuleAction::class)->execute($this->billingModule);
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);

    if ($this->originalContent !== null) {
        File::put($this->composerPath, $this->originalContent);
    } else {
        File::delete($this->composerPath);
    }
});

test('forModule returns an empty array when Models directory does not exist', function () {
    // Delete Models directory in Account
    File::deleteDirectory($this->accountModule->path.'/Models');

    $action = app(DiscoverModelsAction::class);
    $models = $action->forModule($this->accountModule);

    expect($models)->toBe([]);
});

test('forModule discovers models and maps them to FQCNs', function () {
    $creator = app(CreateModelAction::class);
    $creator->execute($this->accountModule, 'Flight');
    $creator->execute($this->accountModule, 'Booking');

    $action = app(DiscoverModelsAction::class);
    $models = $action->forModule($this->accountModule);

    expect($models)->toBe([
        'Booking' => 'Studeo\\Account\\Models\\Booking',
        'Flight' => 'Studeo\\Account\\Models\\Flight',
    ]);
});

test('forModule ignores non-PHP files in Models directory', function () {
    $creator = app(CreateModelAction::class);
    $creator->execute($this->accountModule, 'Flight');

    File::put($this->accountModule->path.'/Models/.gitkeep', '');
    File::put($this->accountModule->path.'/Models/notes.txt', 'notes');

    $action = app(DiscoverModelsAction::class);
    $models = $action->forModule($this->accountModule);

    expect($models)->toBe([
        'Flight' => 'Studeo\\Account\\Models\\Flight',
    ]);
});

test('execute with a module parameter returns models for that module only', function () {
    $creator = app(CreateModelAction::class);
    $creator->execute($this->accountModule, 'Flight');

    $action = app(DiscoverModelsAction::class);
    $result = $action->execute($this->accountModule);

    expect($result)->toBe([
        'Account' => [
            'Flight' => 'Studeo\\Account\\Models\\Flight',
        ],
    ]);
});

test('execute returns empty array when no modules are installed in root composer', function () {
    $creator = app(CreateModelAction::class);
    $creator->execute($this->accountModule, 'Flight');

    // Modules exist on disk but not in root composer.json
    $action = app(DiscoverModelsAction::class);
    $result = $action->execute();

    expect($result)->toBe([]);
});

test('execute discovers models across all installed modules', function () {
    $creator = app(CreateModelAction::class);
    $creator->execute($this->accountModule, 'Flight');
    $creator->execute($this->accountModule, 'Booking');
    $creator->execute($this->billingModule, 'Invoice');

    // Install both modules into root composer
    $composer = json_decode(File::get($this->composerPath), true);
    $composer['autoload']['psr-4']['Studeo\\Account\\'] = 'modules/Account/';
    $composer['autoload']['psr-4']['Studeo\\Billing\\'] = 'modules/Billing/';
    File::put($this->composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

    $action = app(DiscoverModelsAction::class);
    $result = $action->execute();

    expect($result)->toBe([
        'Account' => [
            'Booking' => 'Studeo\\Account\\Models\\Booking',
            'Flight' => 'Studeo\\Account\\Models\\Flight',
        ],
        'Billing' => [
            'Invoice' => 'Studeo\\Billing\\Models\\Invoice',
        ],
    ]);
});

test('execute ignores uninstalled modules', function () {
    $creator = app(CreateModelAction::class);
    $creator->execute($this->accountModule, 'Flight');
    $creator->execute($this->billingModule, 'Invoice');

    // Only install Account
    $composer = json_decode(File::get($this->composerPath), true);
    $composer['autoload']['psr-4']['Studeo\\Account\\'] = 'modules/Account/';
    File::put($this->composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

    $action = app(DiscoverModelsAction::class);
    $result = $action->execute();

    expect($result)->toHaveKey('Account')
        ->and($result)->not->toHaveKey('Billing')
        ->and($result['Account'])->toBe([
            'Flight' => 'Studeo\\Account\\Models\\Flight',
        ]);
});
