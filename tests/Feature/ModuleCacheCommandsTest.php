<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Composer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use Studeo\Modular\Console\Actions\DiscoverInstalledModulesAction;
use Studeo\Modular\Providers\ModularServiceProvider;
use Studeo\Modular\Support\ModuleCache;
use Studeo\Modular\Support\ModuleLoadFailures;
use Studeo\Modular\Support\ModuleScanner;
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
});

afterEach(function () {
    File::deleteDirectory($this->modulesPath);

    $this->originalRootComposer !== null
        ? File::put($this->rootComposerPath, $this->originalRootComposer)
        : File::delete($this->rootComposerPath);
});

function liveScanOf(string $module): array
{
    $installed = app(DiscoverInstalledModulesAction::class)->execute()[$module];
    $scanner = new ModuleScanner($installed->module->path, $installed->manifest, new Filesystem);

    return ['listeners' => $scanner->listeners(), 'policies' => $scanner->policies(), 'middleware' => $scanner->middleware()];
}

function cacheDirectoryListing(object $test): array
{
    return array_values(array_diff(scandir(dirname($test->moduleCachePath())), ['.', '..']));
}

it('writes bootstrap/cache/modular.php with exactly what live scanning finds, keyed by module key', function () {
    $this->generateCacheableModule('BlogPosts');
    $this->generateCacheableModule('Billing');
    $before = $this->rootAppSnapshot();
    $listingBefore = cacheDirectoryListing($this);

    $this->artisan('modular:cache')->assertSuccessful();

    $data = include $this->moduleCachePath();

    expect($data['version'])->toBe(ModuleCache::VERSION)
        ->and(array_keys($data['modules']))->toBe(['billing', 'blog-posts'])
        ->and($data['modules']['blog-posts'])->toBe(liveScanOf('BlogPosts'))
        ->and($data['modules']['billing'])->toBe(liveScanOf('Billing'))
        ->and($data['modules']['billing']['middleware'])->toBe(['billing.ensure-active' => 'Studeo\\Billing\\Middleware\\EnsureActive'])
        ->and(array_values(array_diff(cacheDirectoryListing($this), $listingBefore)))->toBe(['modular.php'])
        ->and($this->rootAppSnapshot())->toBe($before);
});

it('writes nothing outside bootstrap/cache/modular.php, and nothing before modular:cache runs', function () {
    $this->generateCacheableModule('Billing');

    expect($this->moduleCachePath())->not->toBeFile();

    $this->artisan('modular:cache')->assertSuccessful();

    expect($this->moduleCachePath())->toBeFile();
});

it('says that cached entries are not revalidated until modular:clear', function () {
    $this->generateCacheableModule('Billing');

    $this->artisan('modular:cache')
        ->expectsOutputToContain('modular:clear')
        ->assertSuccessful();
});

it('exits 1, prints the failures and writes nothing when a module fails to load', function () {
    $this->generateCacheableModule('Billing');
    $this->generateCacheableModule('Broken');
    $this->writeRawModuleManifest($this->modulesPath.'/Broken', '{ broken');

    $this->artisan('modular:cache')
        ->expectsOutputToContain('Module [Broken] failed to load')
        ->assertFailed();

    expect($this->moduleCachePath())->not->toBeFile();
});

it('does not replace an existing cache when a module fails to load', function () {
    $this->generateCacheableModule('Billing');
    $this->artisan('modular:cache')->assertSuccessful();
    $existing = File::get($this->moduleCachePath());
    $this->generateCacheableModule('Broken');
    $this->writeRawModuleManifest($this->modulesPath.'/Broken', '{ broken');

    $this->artisan('modular:cache')->assertFailed();

    expect(File::get($this->moduleCachePath()))->toBe($existing);
});

it('exits 1 and writes nothing when a boot-phase registration failure was recorded', function () {
    $this->generateCacheableModule('Billing');
    app('router')->aliasMiddleware('billing.ensure-active', 'App\\Http\\Middleware\\Taken');
    (new ModularServiceProvider($this->app))->register();

    $this->artisan('modular:cache')
        ->expectsOutputToContain('billing.ensure-active')
        ->assertFailed();

    expect($this->moduleCachePath())->not->toBeFile();
});

it('caches an empty module list when nothing is installed', function () {
    $this->artisan('modular:cache')->assertSuccessful();

    expect((include $this->moduleCachePath())['modules'])->toBe([]);
});

it('modular:clear removes the file and is silent and idempotent when it is missing', function () {
    $this->generateCacheableModule('Billing');
    $this->artisan('modular:cache')->assertSuccessful();

    $this->artisan('modular:clear')->assertSuccessful();
    expect($this->moduleCachePath())->not->toBeFile();

    $this->artisan('modular:clear')->doesntExpectOutputToContain('rror')->assertSuccessful();
});

it('is not touched by install, uninstall or remove', function () {
    $this->generateCacheableModule('Billing');
    $this->artisan('modular:cache')->assertSuccessful();
    $cached = File::get($this->moduleCachePath());

    $this->artisan('module:uninstall', ['module' => 'Billing'])->assertSuccessful();
    $this->artisan('module:install', ['module' => 'Billing'])->assertSuccessful();
    $this->artisan('module:uninstall', ['module' => 'Billing'])->assertSuccessful();
    $this->artisan('module:remove', ['module' => 'Billing', '--force' => true])->assertSuccessful();

    expect(File::get($this->moduleCachePath()))->toBe($cached);
});

it('hooks the module cache and modular:clear into optimize and optimize:clear', function () {
    $this->generateCacheableModule('Billing');

    expect(ServiceProvider::$optimizeCommands)->toMatchArray(['modular' => 'modular:optimize'])
        ->and(ServiceProvider::$optimizeClearCommands)->toMatchArray(['modular' => 'modular:clear']);

    $this->artisan('optimize', ['--except' => 'config,events,routes,views'])->assertSuccessful();
    expect($this->moduleCachePath())->toBeFile();

    $this->artisan('optimize:clear', ['--except' => 'config,cache,compiled,events,routes,views'])->assertSuccessful();
    expect($this->moduleCachePath())->not->toBeFile();
});

it('makes optimize throw the load failures, writing nothing, when a module fails to load', function () {
    $this->generateCacheableModule('Billing');
    $this->generateCacheableModule('Broken');
    $this->writeRawModuleManifest($this->modulesPath.'/Broken', '{ broken');

    expect(fn () => Artisan::call('optimize', ['--except' => 'config,events,routes,views']))
        ->toThrow(RuntimeException::class, 'Module [Broken] failed to load')
        ->and($this->moduleCachePath())->not->toBeFile();
});

it('judges failures from its own live scan: a collision recorded from a stale cache, since fixed in the files, no longer blocks it', function () {
    $this->generateCacheableModule('Billing');
    $this->artisan('modular:cache')->assertSuccessful();
    app('router')->aliasMiddleware('billing.ensure-active', 'App\\Http\\Middleware\\Taken');
    (new ModularServiceProvider($this->app))->register();

    // Boot-time state, read through the stale cache, now holds the collision.
    expect($this->app->make(ModuleLoadFailures::class)->all())->not->toBe([]);

    $middleware = $this->modulesPath.'/Billing/Middleware';
    File::put($middleware.'/EnsureVerified.php', str_replace('EnsureActive', 'EnsureVerified', File::get($middleware.'/EnsureActive.php')));
    File::delete($middleware.'/EnsureActive.php');

    $this->artisan('modular:cache')->assertSuccessful();

    expect((include $this->moduleCachePath())['modules']['billing'])
        ->toBe(liveScanOf('Billing'))
        ->and((include $this->moduleCachePath())['modules']['billing']['middleware'])
        ->toBe(['billing.ensure-verified' => 'Studeo\\Billing\\Middleware\\EnsureVerified']);
});

it('judges failures from its own live scan: a collision in the live files blocks it although the stale cache booted cleanly', function () {
    $this->generateCacheableModule('Billing');
    $this->artisan('modular:cache')->assertSuccessful();
    $existing = File::get($this->moduleCachePath());
    File::put($this->modulesPath.'/Billing/Middleware/Extra.php', "<?php\n\nnamespace Studeo\\Billing\\Middleware;\n\nclass Extra\n{\n}\n");
    app('router')->aliasMiddleware('billing.extra', 'App\\Http\\Middleware\\Taken');
    (new ModularServiceProvider($this->app))->register();

    // The stale cache knows nothing of Extra, so boot recorded no failure.
    expect($this->app->make(ModuleLoadFailures::class)->all())->toBe([]);

    $this->artisan('modular:cache')
        ->expectsOutputToContain('billing.extra')
        ->assertFailed();

    expect(File::get($this->moduleCachePath()))->toBe($existing);
});
