<?php

use Illuminate\Support\Composer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\DiscoverInstalledModulesAction;
use Studeo\Modular\Console\Actions\DiscoverModulesAction;
use Studeo\Modular\Providers\ModularServiceProvider;
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
});

afterEach(function () {
    File::deleteDirectory($this->modulesPath);

    if ($this->originalRootComposer !== null) {
        File::put($this->rootComposerPath, $this->originalRootComposer);
    } else {
        File::delete($this->rootComposerPath);
    }
});

it('scaffolds a module whose manifest is module.json with only name and namespace', function () {
    $this->artisan('module:make', ['module' => 'Conformance'])->assertSuccessful();

    $manifest = json_decode(File::get($this->modulesPath.'/Conformance/module.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest)->toBe(['name' => 'Conformance', 'namespace' => 'Studeo\\Conformance'])
        ->and($this->modulesPath.'/Conformance/composer.json')->not->toBeFile();
});

it('finds a generated module with discovery, installs it, and registers it at boot', function () {
    $this->artisan('module:make', ['module' => 'Conformance'])->assertSuccessful();

    expect(array_keys(app(DiscoverModulesAction::class)->execute()))->toBe(['Conformance']);

    $this->artisan('module:install', ['module' => 'Conformance'])->assertSuccessful();

    $psr4 = json_decode(File::get($this->rootComposerPath), true)['autoload']['psr-4'];
    expect($psr4)->toMatchArray([
        'Studeo\\Conformance\\' => 'modules/Conformance/',
        'Studeo\\Conformance\\Database\\Factories\\' => 'modules/Conformance/database/factories/',
        'Studeo\\Conformance\\Database\\Seeders\\' => 'modules/Conformance/database/seeders/',
    ]);

    $installed = app(DiscoverInstalledModulesAction::class)->execute();
    expect(array_keys($installed))->toBe(['Conformance'])
        ->and($installed['Conformance']->manifest->provider())->toBe('Studeo\\Conformance\\ConformanceServiceProvider');

    // composer dump-autoload would make the class loadable in a real app.
    require_once $this->modulesPath.'/Conformance/ConformanceServiceProvider.php';

    $provider = new ModularServiceProvider($this->app);
    $provider->register();

    expect($this->app->getProvider('Studeo\\Conformance\\ConformanceServiceProvider'))->not->toBeNull();
});

it('fails loudly on a legacy composer.json module and prints the module.json to create', function () {
    File::ensureDirectoryExists($this->modulesPath.'/Legacy');
    File::put($this->modulesPath.'/Legacy/composer.json', '{"name": "studeo/legacy"}');

    expect(app(DiscoverModulesAction::class)->execute())->toBe([]);

    $exitCode = Artisan::call('module:install', ['module' => 'Legacy']);
    $output = Artisan::output();

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain($this->modulesPath.'/Legacy/module.json')
        ->and($output)->toContain('"name": "Legacy"')
        ->and($output)->toContain('"namespace": "Studeo\\\\Legacy"');
});
