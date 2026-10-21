<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\Console\Actions\InstallModuleAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Tests\Support\ModulesRootFixture;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->fixture = new ModulesRootFixture;
    $this->composerPath = $this->fixture->composerPath;
    $this->fixture->writeRoot();
    $this->fixture->fakeComposer();
    $this->rootModulesPath = $this->fixture->rootPath;
    $this->accountModule = $this->fixture->definition('Account');
    $this->billingModule = $this->fixture->definition('Billing');
});

afterEach(fn () => $this->fixture->tearDown());

it('initializes autoload.psr-4 with every mapping of the manifest psr4 in order when autoload is missing', function () {
    app(CreateModuleAction::class)->execute($this->accountModule);
    File::put($this->composerPath, json_encode(['name' => 'laravel/laravel']).PHP_EOL);

    app(InstallModuleAction::class)->execute($this->accountModule);

    $composer = json_decode(File::get($this->composerPath), true);
    expect($composer['autoload']['psr-4'])->toBe([
        'Studeo\\Account\\' => 'modules/Account/',
        'Studeo\\Account\\Database\\Factories\\' => 'modules/Account/database/factories/',
        'Studeo\\Account\\Database\\Seeders\\' => 'modules/Account/database/seeders/',
    ]);
});

it('keeps existing autoload.psr-4 other keys when registering a module', function () {
    app(CreateModuleAction::class)->execute($this->accountModule);
    File::put($this->composerPath, json_encode([
        'name' => 'laravel/laravel',
        'autoload' => [
            'psr-4' => [
                'App\\' => 'app/',
                'OtherVendor\\Package\\' => 'packages/other/',
            ],
        ],
    ]).PHP_EOL);

    app(InstallModuleAction::class)->execute($this->accountModule);

    $composer = json_decode(File::get($this->composerPath), true);
    expect($composer['autoload']['psr-4'])->toHaveKey('App\\', 'app/')
        ->and($composer['autoload']['psr-4'])->toHaveKey('OtherVendor\\Package\\', 'packages/other/')
        ->and($composer['autoload']['psr-4'])->toHaveKey('Studeo\\Account\\', 'modules/Account/')
        ->and($composer['autoload']['psr-4'])->toHaveKey('Studeo\\Account\\Database\\Factories\\', 'modules/Account/database/factories/')
        ->and($composer['autoload']['psr-4'])->toHaveKey('Studeo\\Account\\Database\\Seeders\\', 'modules/Account/database/seeders/');
});

it('replaces autoload.psr-4 with an array holding only the new mappings when psr-4 is not an array', function () {
    app(CreateModuleAction::class)->execute($this->accountModule);
    File::put($this->composerPath, json_encode([
        'name' => 'laravel/laravel',
        'autoload' => [
            'psr-4' => 'corrupted-string',
        ],
    ]).PHP_EOL);

    app(InstallModuleAction::class)->execute($this->accountModule);

    $composer = json_decode(File::get($this->composerPath), true);
    expect($composer['autoload']['psr-4'])->toBe([
        'Studeo\\Account\\' => 'modules/Account/',
        'Studeo\\Account\\Database\\Factories\\' => 'modules/Account/database/factories/',
        'Studeo\\Account\\Database\\Seeders\\' => 'modules/Account/database/seeders/',
    ]);
});

it('resolves a module path under base_path relative to base_path with trailing slash and subpath', function () {
    app(CreateModuleAction::class)->execute($this->accountModule);

    app(InstallModuleAction::class)->execute($this->accountModule);

    $composer = json_decode(File::get($this->composerPath), true);
    expect($composer['autoload']['psr-4']['Studeo\\Account\\'])->toBe('modules/Account/')
        ->and($composer['autoload']['psr-4']['Studeo\\Account\\Database\\Factories\\'])->toBe('modules/Account/database/factories/')
        ->and($composer['autoload']['psr-4']['Studeo\\Account\\Database\\Seeders\\'])->toBe('modules/Account/database/seeders/');
});

it('keeps an outside base_path module path as given with backslashes turned into slashes trimmed and trailing slash', function () {
    $parent = sys_get_temp_dir().DIRECTORY_SEPARATOR.'modular_ext_'.uniqid().'\\custom\\outside';
    @mkdir($parent, 0777, true);
    $outsidePath = $parent.'/External';
    $externalModule = new ModuleDefinition('External', 'Studeo\\External', $outsidePath);
    app(CreateModuleAction::class)->execute($externalModule);

    app(InstallModuleAction::class)->execute($externalModule);

    $composer = json_decode(File::get($this->composerPath), true);
    $expectedBase = trim(str_replace('\\', '/', $outsidePath), '/').'/';
    expect($composer['autoload']['psr-4']['Studeo\\External\\'])->toBe($expectedBase)
        ->and($composer['autoload']['psr-4']['Studeo\\External\\Database\\Factories\\'])->toBe($expectedBase.'database/factories/')
        ->and($composer['autoload']['psr-4']['Studeo\\External\\Database\\Seeders\\'])->toBe($expectedBase.'database/seeders/');

    File::deleteDirectory($parent);
});

it('registers two pending modules in one call', function () {
    app(CreateModuleAction::class)->execute($this->accountModule);
    app(CreateModuleAction::class)->execute($this->billingModule);

    app(InstallModuleAction::class)->execute($this->accountModule, $this->billingModule);

    $composer = json_decode(File::get($this->composerPath), true);
    expect($composer['autoload']['psr-4'])->toHaveKey('Studeo\\Account\\', 'modules/Account/')
        ->and($composer['autoload']['psr-4'])->toHaveKey('Studeo\\Account\\Database\\Factories\\', 'modules/Account/database/factories/')
        ->and($composer['autoload']['psr-4'])->toHaveKey('Studeo\\Account\\Database\\Seeders\\', 'modules/Account/database/seeders/')
        ->and($composer['autoload']['psr-4'])->toHaveKey('Studeo\\Billing\\', 'modules/Billing/')
        ->and($composer['autoload']['psr-4'])->toHaveKey('Studeo\\Billing\\Database\\Factories\\', 'modules/Billing/database/factories/')
        ->and($composer['autoload']['psr-4'])->toHaveKey('Studeo\\Billing\\Database\\Seeders\\', 'modules/Billing/database/seeders/');
});
