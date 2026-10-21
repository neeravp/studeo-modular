<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Composer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\DiscoverInstalledModulesAction;
use Studeo\Modular\Exceptions\ModuleLoadException;
use Studeo\Modular\Providers\ModularServiceProvider;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->rootComposerPath = base_path('composer.json');
    $this->originalRootComposer = File::exists($this->rootComposerPath) ? File::get($this->rootComposerPath) : null;
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

    // A module whose provider was generated before the registrar existed.
    $this->artisan('module:make', ['module' => 'Legacy'])->assertSuccessful();
    File::put($this->modulesPath.'/Legacy/LegacyServiceProvider.php', <<<'PHP'
<?php

namespace Studeo\Legacy;

use Illuminate\Support\ServiceProvider;

class LegacyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/legacy.php', 'legacy');
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes/web.php');
    }
}
PHP);

    $composerJson = json_decode(File::get($this->rootComposerPath), true);
    $composerJson['autoload']['psr-4']['Studeo\\Legacy\\'] = 'modules/Legacy/';
    File::put($this->rootComposerPath, json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
});

afterEach(function () {
    File::deleteDirectory($this->modulesPath);

    $this->originalRootComposer !== null
        ? File::put($this->rootComposerPath, $this->originalRootComposer)
        : File::delete($this->rootComposerPath);
});

/**
 * Laravel skips the Symfony-to-Laravel command event bridge under unit tests; a real
 * artisan process has it. The console application is already built by module:make in
 * beforeEach, so rebuild it after wiring the bridge.
 */
function bridgeLegacyCommandEvents(): void
{
    $kernel = app(Kernel::class);
    $kernel->rerouteSymfonyCommandEvents();
    $kernel->setArtisan(null);
}

it('fails a module whose provider does not use ModuleRegistrar, with the replacement provider and the key note', function () {
    $discovered = app(DiscoverInstalledModulesAction::class)->discover();

    expect($discovered->installed)->toBe([])
        ->and($discovered->failures['Legacy']->getMessage())
        ->toContain('Module [Legacy] failed to load')
        ->toContain('does not use ModuleRegistrar')
        ->toContain('namespace Studeo\\Legacy;')
        ->toContain('class LegacyServiceProvider extends ServiceProvider')
        ->toContain('ModuleRegistrar::for($this, __DIR__)')
        ->toContain('kebab case');
});

it('still runs artisan and warns about the legacy provider', function () {
    (new ModularServiceProvider($this->app))->register();
    bridgeLegacyCommandEvents();

    expect(Artisan::call('list'))->toBe(0)
        ->and(Artisan::output())->toContain('Module [Legacy] failed to load')->toContain('ModuleRegistrar::for($this, __DIR__)');
});

it('suppresses the legacy provider warning with --quiet', function () {
    (new ModularServiceProvider($this->app))->register();
    bridgeLegacyCommandEvents();

    expect(Artisan::call('list', ['--quiet' => true]))->toBe(0)
        ->and(Artisan::output())->not->toContain('failed to load');
});

it('throws the legacy provider failure for HTTP requests only in debug mode', function () {
    config()->set('app.debug', true);
    (new ReflectionProperty($this->app, 'isRunningInConsole'))->setValue($this->app, false);

    (new ModularServiceProvider($this->app))->register();
})->throws(ModuleLoadException::class, 'does not use ModuleRegistrar');

it('refuses to install a module whose provider does not use ModuleRegistrar', function () {
    $composerJson = json_decode(File::get($this->rootComposerPath), true);
    unset($composerJson['autoload']['psr-4']['Studeo\\Legacy\\']);
    File::put($this->rootComposerPath, json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

    $exitCode = Artisan::call('module:install', ['module' => 'Legacy']);

    expect($exitCode)->not->toBe(0)
        ->and(Artisan::output())->toContain('does not use ModuleRegistrar')->toContain('ModuleRegistrar::for($this, __DIR__)')
        ->and(File::get($this->rootComposerPath))->not->toContain('Studeo\\\\Legacy');
});
