<?php

use Illuminate\Support\Composer;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Providers\ModularServiceProvider;
use Studeo\Modular\Support\ModuleLoadFailures;
use Studeo\Modular\Tests\TestCase;
use Symfony\Component\Process\Process;

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

/**
 * Boots a fresh application in its own PHP process, where nothing from the test
 * process (loaded classes, cache memo) can leak in, and reports what the modules
 * registered: listeners, policies and middleware aliases.
 *
 * @return array{listeners: array, policies: array, middleware: array}
 */
function bootedModuleRegistrations(string $modulesPath): array
{
    $script = <<<'PHP'
        require $argv[1];
        spl_autoload_register(function (string $class) use ($argv): void {
            if (preg_match('/^Studeo\\\\(\w+)\\\\(.+)$/', $class, $match)
                && is_file($file = $argv[2].'/'.$match[1].'/'.str_replace('\\', '/', $match[2]).'.php')) {
                require $file;
            }
        });
        $app = Orchestra\Testbench\Foundation\Application::create();
        $app['config']->set('modular.root_path', $argv[2]);
        $app['config']->set('modular.root_namespace', 'Studeo');
        $app->register(Studeo\Modular\Providers\ModularServiceProvider::class);
        $ours = fn (array $items, bool $byKey) => array_filter($items, fn ($value, $key) => str_starts_with($byKey ? $key : $value, 'Studeo\\'), ARRAY_FILTER_USE_BOTH);
        echo json_encode([
            'listeners' => $ours($app['events']->getRawListeners(), true),
            'policies' => $ours($app->make(Illuminate\Contracts\Auth\Access\Gate::class)->policies(), true),
            'middleware' => $ours($app['router']->getMiddleware(), false),
        ]);
        PHP;

    $process = new Process([PHP_BINARY, '-r', $script, dirname(__DIR__, 2).'/vendor/autoload.php', $modulesPath]);
    $process->run();

    return json_decode($process->getOutput(), true) ?? ['error' => $process->getOutput().$process->getErrorOutput()];
}

function deleteCachedSources(string $modulesPath, string $module): void
{
    foreach (['Listeners', 'Policies', 'Middleware'] as $directory) {
        File::deleteDirectory("{$modulesPath}/{$module}/{$directory}");
    }
}

it('boots from the cache with the same listeners, policies and middleware as a live scan', function () {
    $this->generateCacheableModule('Billing');

    $live = bootedModuleRegistrations($this->modulesPath);
    $this->artisan('modular:cache')->assertSuccessful();
    // Without these files a live scan finds nothing, so only the cache can supply them.
    deleteCachedSources($this->modulesPath, 'Billing');
    $cached = bootedModuleRegistrations($this->modulesPath);

    expect($live['listeners'])->not->toBe([])
        ->and($live['policies'])->not->toBe([])
        ->and($live['middleware'])->toBe(['billing.ensure-active' => 'Studeo\\Billing\\Middleware\\EnsureActive'])
        ->and($cached)->toBe($live);
});

it('registers nothing for the deleted sources when the cache is cleared', function () {
    $this->generateCacheableModule('Billing');
    $this->artisan('modular:cache')->assertSuccessful();
    deleteCachedSources($this->modulesPath, 'Billing');
    $this->artisan('modular:clear')->assertSuccessful();

    expect(bootedModuleRegistrations($this->modulesPath))->toBe(['listeners' => [], 'policies' => [], 'middleware' => []]);
});

it('scans a module added after caching live, and still serves the cached module from the cache', function () {
    $this->generateCacheableModule('Billing');
    $this->artisan('modular:cache')->assertSuccessful();
    deleteCachedSources($this->modulesPath, 'Billing');
    $this->generateCacheableModule('Shop');

    $booted = bootedModuleRegistrations($this->modulesPath);

    expect($booted['middleware'])->toBe([
        'billing.ensure-active' => 'Studeo\\Billing\\Middleware\\EnsureActive',
        'shop.ensure-active' => 'Studeo\\Shop\\Middleware\\EnsureActive',
    ])->and(array_keys($booted['listeners']))->toBe(['Studeo\\Billing\\Events\\OrderPlaced', 'Studeo\\Shop\\Events\\OrderPlaced']);
});

it('ignores the cached entry of a module that is no longer installed', function () {
    $this->generateCacheableModule('Billing');
    $this->generateCacheableModule('Shop');
    $this->artisan('modular:cache')->assertSuccessful();
    $this->artisan('module:uninstall', ['module' => 'Shop'])->assertSuccessful();

    $booted = bootedModuleRegistrations($this->modulesPath);

    expect(array_keys($booted['middleware']))->toBe(['billing.ensure-active'])
        ->and(array_keys($booted['policies']))->toBe(['Studeo\\Billing\\Models\\Flight']);
});

it('ignores a cache with an unknown version or corrupt contents and scans live', function (string $contents) {
    $this->generateCacheableModule('Billing');
    $bogus = ['listeners' => [], 'policies' => [], 'middleware' => ['billing.bogus' => 'Studeo\\Billing\\Middleware\\Bogus']];
    file_put_contents($this->moduleCachePath(), str_replace('%BOGUS%', var_export(['version' => 999, 'modules' => ['billing' => $bogus]], true), $contents));

    $booted = bootedModuleRegistrations($this->modulesPath);

    expect($booted['middleware'])->toBe(['billing.ensure-active' => 'Studeo\\Billing\\Middleware\\EnsureActive']);
})->with([
    'unknown version' => '<?php return %BOGUS%;',
    'corrupt' => '<?php return [ "version" => ',
    'not php' => 'not a cache',
]);

it('still runs artisan with a corrupt cache file', function () {
    $this->generateCacheableModule('Billing');
    file_put_contents($this->moduleCachePath(), '<?php return [ "version" => ');

    $this->artisan('list')->assertSuccessful();
});

it('still applies the middleware alias collision check on the cached path', function () {
    $this->generateCacheableModule('Billing');
    $this->artisan('modular:cache')->assertSuccessful();
    app('router')->aliasMiddleware('billing.ensure-active', 'App\\Http\\Middleware\\Taken');

    $registrar = (new ModularServiceProvider($this->app));
    $registrar->register();

    expect($this->app->make(ModuleLoadFailures::class)->all()[0]->getMessage())
        ->toContain('billing.ensure-active');
});
