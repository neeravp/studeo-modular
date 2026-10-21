<?php

use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\Composer;
use Illuminate\Support\Facades\File;
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

    $this->rootAppBefore = $this->rootAppSnapshot();
});

afterEach(function () {
    File::deleteDirectory($this->modulesPath);

    $this->originalRootComposer !== null
        ? File::put($this->rootComposerPath, $this->originalRootComposer)
        : File::delete($this->rootComposerPath);
});

it('registers a generated command, listener, policy and middleware in a fresh app with no edits outside the module', function () {
    $this->artisan('module:make', ['module' => 'Registrar'])->assertSuccessful();
    $this->autoloadModule('Studeo\\Registrar', $this->modulesPath.'/Registrar');

    foreach ([
        ['module:command', ['name' => 'SendInvoices']],
        ['module:event', ['name' => 'OrderPlaced']],
        ['module:listener', ['name' => 'SendReceipt', '--event' => 'OrderPlaced']],
        ['module:model', ['name' => 'Flight', '--no-migration' => true, '--no-factory' => true, '--no-seeder' => true]],
        ['module:policy', ['name' => 'FlightPolicy', '--model' => 'Flight']],
        ['module:middleware', ['name' => 'EnsureActive']],
    ] as [$command, $arguments]) {
        $this->artisan($command, ['--module' => 'Registrar', ...$arguments])->assertSuccessful();
    }

    $this->artisan('module:install', ['module' => 'Registrar'])->assertSuccessful();

    (new ModularServiceProvider($this->app))->register();

    $console = new ConsoleApplication($this->app, $this->app['events'], $this->app->version());

    expect($console->has('registrar:send-invoices'))->toBeTrue()
        ->and($this->app['events']->getRawListeners()['Studeo\\Registrar\\Events\\OrderPlaced'])
        ->toContain('Studeo\\Registrar\\Listeners\\SendReceipt@handle')
        ->and($this->app->make(Gate::class)->policies())
        ->toMatchArray(['Studeo\\Registrar\\Models\\Flight' => 'Studeo\\Registrar\\Policies\\FlightPolicy'])
        ->and(app('router')->getMiddleware())
        ->toMatchArray(['registrar.ensure-active' => 'Studeo\\Registrar\\Middleware\\EnsureActive'])
        ->and($this->rootAppSnapshot())->toBe($this->rootAppBefore);
});

it('generates a provider that calls the registrar and does not register things by hand', function () {
    $this->artisan('module:make', ['module' => 'Registrar'])->assertSuccessful();

    $provider = File::get($this->modulesPath.'/Registrar/RegistrarServiceProvider.php');

    expect($provider)
        ->toContain('use Studeo\\Modular\\Support\\ModuleRegistrar;')
        ->toContain('ModuleRegistrar::for($this, __DIR__)')
        ->toContain('public function register(): void')
        ->toContain('->config();')
        ->toContain('public function boot(): void')
        ->toContain('->routes()->views()->migrations()->translations()')
        ->toContain('->commands()->listeners()->policies()->middleware()->livewire();')
        ->not->toContain('loadRoutesFrom')
        ->not->toContain('mergeConfigFrom');
});
