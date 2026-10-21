<?php

use Illuminate\Support\Composer;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->rootComposerPath = base_path('composer.json');
    $this->originalRootComposer = File::exists($this->rootComposerPath)
        ? File::get($this->rootComposerPath)
        : null;

    File::put($this->rootComposerPath, json_encode([
        'name' => 'laravel/laravel',
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

    $this->modulesPath = config('modular.root_path');
    File::deleteDirectory($this->modulesPath);
    File::ensureDirectoryExists($this->modulesPath);

    $composer = Mockery::mock(Composer::class);
    $composer->shouldReceive('dumpAutoloads')->andReturn(0);
    $this->app->instance(Composer::class, $composer);

    // Install under root_namespace "Studeo", then change the config.
    $this->artisan('module:make', ['module' => 'Drift'])->assertSuccessful();
    $this->artisan('module:install', ['module' => 'Drift'])->assertSuccessful();
    config()->set('modular.root_namespace', 'Renamed');
});

afterEach(function () {
    File::deleteDirectory($this->modulesPath);

    if ($this->originalRootComposer !== null) {
        File::put($this->rootComposerPath, $this->originalRootComposer);
    } else {
        File::delete($this->rootComposerPath);
    }
});

function rootPsr4Keys(): array
{
    return array_keys(json_decode(File::get(base_path('composer.json')), true)['autoload']['psr-4']);
}

it('uninstalls a module after root_namespace changed', function () {
    $this->artisan('module:uninstall', ['module' => 'Drift'])
        ->expectsOutput('Module [Drift] uninstalled successfully.')
        ->assertSuccessful();

    expect(rootPsr4Keys())->toBe(['App\\']);
});

it('refuses to remove an installed module after root_namespace changed', function () {
    $this->artisan('module:remove', ['module' => 'Drift', '--force' => true])->assertFailed();

    expect($this->modulesPath.'/Drift')->toBeDirectory();
});

it('removes a module after uninstalling it when root_namespace changed', function () {
    $this->artisan('module:uninstall', ['module' => 'Drift'])->assertSuccessful();
    $this->artisan('module:remove', ['module' => 'Drift', '--force' => true])->assertSuccessful();

    expect($this->modulesPath.'/Drift')->not->toBeDirectory();
});

it('still falls back to the root composer.json entry when the manifest is invalid', function () {
    $this->writeRawModuleManifest($this->modulesPath.'/Drift', '{ broken');

    $this->artisan('module:uninstall', ['module' => 'Drift'])->assertSuccessful();

    expect(rootPsr4Keys())->toBe(['App\\']);
});

it('still falls back to the root composer.json entry when the manifest is missing', function () {
    File::delete($this->modulesPath.'/Drift/module.json');

    $this->artisan('module:uninstall', ['module' => 'Drift'])->assertSuccessful();

    expect(rootPsr4Keys())->toBe(['App\\']);
});

it('reports the module as already installed in bulk install after root_namespace changed', function () {
    $this->artisan('module:install', ['--all' => true])
        ->expectsOutput('All modules are already installed.')
        ->assertSuccessful();
});
