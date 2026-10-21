<?php

use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Studeo\Modular\Support\ModuleRegistrar;
use Studeo\Modular\Tests\Support\ModulesRootFixture;
use Studeo\Modular\Tests\Support\RegistrarModule;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->fixture = new ModulesRootFixture(usesComposer: false);
    $this->modulesPath = $this->fixture->rootPath;

    $this->registrarModule = new RegistrarModule($this);
});

afterEach(fn () => $this->fixture->tearDown());

it('merges config under the kebab-case module key', function () {
    $this->registrarModule->registrar($this->registrarModule->build('BlogPosts'))->config();

    expect(config('blog-posts.answer'))->toBe(42);
});

it('registers views, migrations and translations under the module key', function () {
    $directory = $this->registrarModule->build('BlogPosts');

    $this->registrarModule->registrar($directory)->views()->migrations()->translations();

    expect(view()->exists('blog-posts::hello'))->toBeTrue()
        ->and(app('migrator')->paths())->toContain($directory.'/database/migrations')
        ->and(trans('blog-posts::messages.hi'))->toBe('Hello');
});

it('loads web and api routes when present', function () {
    $this->registrarModule->registrar($this->registrarModule->build())->routes();
    Route::getRoutes()->refreshNameLookups();

    expect(Route::has('reg.web'))->toBeTrue()
        ->and(Route::has('reg.api'))->toBeTrue();
});

it('registers every command in Commands, recursively, and skips non-commands', function () {
    $this->registrarModule->registrar($this->registrarModule->build())->commands();

    $console = new ConsoleApplication($this->app, $this->app['events'], $this->app->version());

    expect($console->has('reg:ping'))->toBeTrue()
        ->and($console->has('reg:pong'))->toBeTrue();
});

it('does not scan or register commands outside the console', function () {
    (new ReflectionProperty($this->app, 'isRunningInConsole'))->setValue($this->app, false);

    $this->registrarModule->registrar($this->registrarModule->build())->commands();

    $console = new ConsoleApplication($this->app, $this->app['events'], $this->app->version());

    expect($console->has('reg:ping'))->toBeFalse();
});

it('registers discovered listeners, recursively', function () {
    $this->registrarModule->registrar($this->registrarModule->build())->listeners();

    expect($this->app['events']->getRawListeners()['Studeo\\Reg\\Events\\UserRegistered'])
        ->toContain('Studeo\\Reg\\Listeners\\NotifyUser@handle')
        ->toContain('Studeo\\Reg\\Listeners\\Billing\\ChargeCard@__invoke');
});

it('pairs policies with models by relative path and skips policies without a model', function () {
    $this->registrarModule->registrar($this->registrarModule->build())->policies();

    $policies = $this->app->make(Gate::class)->policies();

    expect($policies)->toMatchArray([
        'Studeo\\Reg\\Models\\Flight' => 'Studeo\\Reg\\Policies\\FlightPolicy',
        'Studeo\\Reg\\Models\\Billing\\Invoice' => 'Studeo\\Reg\\Policies\\Billing\\InvoicePolicy',
    ])->and(array_values($policies))->not->toContain('Studeo\\Reg\\Policies\\OrphanPolicy');
});

it('defers module routes until the app has booted so Route::pattern() from an app provider applies', function () {
    $directory = $this->registrarModule->build();
    $this->registrarModule->file($directory, 'routes/web.php', "use Illuminate\\Support\\Facades\\Route;\nRoute::get('/reg/{id}', fn () => 'show')->name('reg.show');");

    $app = $this->app;
    $set = fn (string $property, mixed $value) => (new ReflectionProperty($app, $property))->setValue($app, $value);

    // Simulate the boot window: providers boot in order, booted callbacks have not fired.
    $set('booted', false);
    $set('bootedCallbacks', []);

    $moduleProvider = new class($app) extends ServiceProvider
    {
        public string $directory = '';

        public function boot(): void
        {
            ModuleRegistrar::for($this, $this->directory)->routes();
        }
    };
    $moduleProvider->directory = $directory;

    $appProvider = new class($app) extends ServiceProvider
    {
        public function boot(): void
        {
            Route::pattern('id', '[0-9]+');
        }
    };

    // Real order: package-registered module providers boot before the app's own providers.
    $moduleProvider->boot();
    $appProvider->boot();

    Route::getRoutes()->refreshNameLookups();
    expect(Route::has('reg.show'))->toBeFalse();

    $set('booted', true);
    $callbacks = (new ReflectionProperty($app, 'bootedCallbacks'))->getValue($app);
    (new ReflectionMethod($app, 'fireAppCallbacks'))->invokeArgs($app, [&$callbacks]);

    Route::getRoutes()->refreshNameLookups();

    expect(Route::has('reg.show'))->toBeTrue()
        ->and(Route::getRoutes()->getByName('reg.show')->wheres)->toBe(['id' => '[0-9]+']);
});
