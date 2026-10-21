<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Exceptions\ModuleLoadException;
use Studeo\Modular\Providers\ModularServiceProvider;
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

test('modular service provider is loaded', function () {
    expect(
        app()->getProvider(
            ModularServiceProvider::class
        )
    )->not->toBeNull();
});

test('it registers installed module service providers during boot', function () {
    app(CreateModuleAction::class)->execute($this->accountModule);

    // Register in root composer
    $composer = json_decode(File::get($this->composerPath), true);
    $composer['autoload']['psr-4']['Studeo\\Account\\'] = 'modules/Account/';
    File::put($this->composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

    // In a real application, `composer dump-autoload` makes the class autoloadable by Composer.
    // In this test, we load the generated fixture class into memory for the active test process:
    require_once $this->accountModule->path.'/AccountServiceProvider.php';

    // Re-register ModularServiceProvider so it discovers the new installed module
    $provider = new ModularServiceProvider($this->app);
    $provider->register();
    $provider->boot();

    expect($this->app->getProvider('Studeo\\Account\\AccountServiceProvider'))->not->toBeNull();
});

test('it does not register uninstalled module service providers', function () {
    app(CreateModuleAction::class)->execute($this->accountModule);

    // Re-register ModularServiceProvider while Account is NOT in root composer
    $provider = new ModularServiceProvider($this->app);
    $provider->register();
    $provider->boot();

    expect($this->app->getProvider('Studeo\\Account\\AccountServiceProvider'))->toBeNull();
});

function installedModuleWithBrokenManifest(object $test): void
{
    app(CreateModuleAction::class)->execute($test->accountModule);
    $test->writeRawModuleManifest($test->accountModule->path, '{ broken');

    $composer = json_decode(File::get($test->composerPath), true);
    $composer['autoload']['psr-4']['Studeo\\Account\\'] = 'modules/Account/';
    File::put($test->composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
}

/**
 * Laravel skips the Symfony-to-Laravel command event bridge under unit tests;
 * a real artisan process has it, so wire it up the way the kernel does.
 */
function bridgeCommandEvents(): void
{
    app(Kernel::class)->rerouteSymfonyCommandEvents();
}

test('it warns before artisan commands when an installed module fails to load', function () {
    installedModuleWithBrokenManifest($this);

    (new ModularServiceProvider($this->app))->register();
    bridgeCommandEvents();

    Artisan::call('list');

    expect(Artisan::output())
        ->toContain('Module [Account] failed to load')
        ->toContain('Invalid module manifest');
});

test('it does not break artisan or throw in the console when a module fails to load, even in debug', function () {
    config()->set('app.debug', true);
    installedModuleWithBrokenManifest($this);

    (new ModularServiceProvider($this->app))->register();
    bridgeCommandEvents();

    expect(Artisan::call('list'))->toBe(0)
        ->and(Artisan::output())->toContain('Module [Account] failed to load');
});

test('it throws a module load failure for HTTP requests in debug mode', function () {
    config()->set('app.debug', true);
    installedModuleWithBrokenManifest($this);
    (new ReflectionProperty($this->app, 'isRunningInConsole'))->setValue($this->app, false);

    (new ModularServiceProvider($this->app))->register();
})->throws(ModuleLoadException::class, 'Module [Account] failed to load');

test('it reports module load failures for HTTP requests when debug is off', function () {
    config()->set('app.debug', false);
    installedModuleWithBrokenManifest($this);
    (new ReflectionProperty($this->app, 'isRunningInConsole'))->setValue($this->app, false);

    $handler = Mockery::mock(ExceptionHandler::class);
    $handler->shouldReceive('report')->once()->with(Mockery::type(ModuleLoadException::class));
    $this->app->instance(ExceptionHandler::class, $handler);

    (new ModularServiceProvider($this->app))->register();
});

test('it routes a module registration failure through the load-failure path and keeps other modules', function () {
    app(CreateModuleAction::class)->execute($this->accountModule);
    $this->autoloadModule('Studeo\\Account', $this->accountModule->path);
    File::ensureDirectoryExists($this->accountModule->path.'/Middleware');
    File::put($this->accountModule->path.'/Middleware/EnsureActive.php', "<?php\n\nnamespace Studeo\\Account\\Middleware;\n\nclass EnsureActive\n{\n}\n");

    $composer = json_decode(File::get($this->composerPath), true);
    $composer['autoload']['psr-4']['Studeo\\Account\\'] = 'modules/Account/';
    File::put($this->composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

    app('router')->aliasMiddleware('account.ensure-active', 'App\\Http\\Middleware\\Taken');

    (new ModularServiceProvider($this->app))->register();
    bridgeCommandEvents();
    Artisan::call('list');

    expect(Artisan::output())
        ->toContain('Module [Account] failed to load')
        ->toContain('account.ensure-active');
});

test('it lets exceptions from a module provider other than registration failures propagate', function () {
    // A module name unique to this test: provider classes stay defined for the whole process.
    $faulty = new ModuleDefinition('Faulty', 'Studeo\\Faulty', $this->rootModulesPath.'/Faulty');
    app(CreateModuleAction::class)->execute($faulty);
    $this->autoloadModule('Studeo\\Faulty', $faulty->path);
    File::put(
        $faulty->path.'/FaultyServiceProvider.php',
        "<?php\n\nnamespace Studeo\\Faulty;\n\nuse Illuminate\\Support\\ServiceProvider;\nuse Studeo\\Modular\\Support\\ModuleRegistrar;\n\nclass FaultyServiceProvider extends ServiceProvider\n{\n    public function register(): void\n    {\n        throw new \\LogicException('provider bug');\n    }\n}\n"
    );

    $composer = json_decode(File::get($this->composerPath), true);
    $composer['autoload']['psr-4']['Studeo\\Faulty\\'] = 'modules/Faulty/';
    File::put($this->composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

    (new ModularServiceProvider($this->app))->register();
})->throws(LogicException::class, 'provider bug');
