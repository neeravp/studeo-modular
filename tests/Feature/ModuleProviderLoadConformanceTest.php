<?php

use Illuminate\Support\Composer;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Tests\TestCase;
use Symfony\Component\Process\Process;

uses(TestCase::class);

beforeEach(function () {
    $this->composerPath = base_path('composer.json');
    $this->originalRootComposer = File::exists($this->composerPath) ? File::get($this->composerPath) : null;
    File::put($this->composerPath, json_encode(['name' => 'laravel/laravel', 'autoload' => ['psr-4' => ['App\\' => 'app/']]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

    $this->modulesPath = config('modular.root_path');
    File::deleteDirectory($this->modulesPath);
    File::ensureDirectoryExists($this->modulesPath);

    $composer = Mockery::mock(Composer::class);
    $composer->shouldReceive('dumpAutoloads')->andReturn(0);
    $this->app->instance(Composer::class, $composer);

    $this->artisan('module:make', ['module' => 'Blog'])->assertSuccessful();
    $this->artisan('module:install', ['module' => 'Blog'])->assertSuccessful();
    $this->rootAppBefore = $this->rootAppSnapshot();
});

afterEach(function () {
    File::deleteDirectory($this->modulesPath);
    $this->originalRootComposer !== null ? File::put($this->composerPath, $this->originalRootComposer) : File::delete($this->composerPath);
});

/**
 * Boots a fresh application in its own PHP process with the installed Blog module, as `artisan`
 * or an HTTP request would, and reports what happened. In console mode it then runs `list` and
 * `module:uninstall Blog`; in http mode the exception handler is a recorder. `autoload` stands
 * for whether composer's autoloader knows the module's namespace (false: it was never dumped).
 * Laravel re-routes console events to `CommandStarting`, which carries the warning,
 * except in the `testing` environment, so the kernel is asked to do what it does on boot elsewhere.
 *
 * @return array{registered: ?string, thrown: ?string, reported: array<int, string>, list: array{int, string}, uninstall: array{int, string}}
 */
function bootedProcess(object $test, string $mode, bool $autoload = false, bool $debug = false): array
{
    $script = <<<'PHP'
        require $argv[1];
        if ($argv[4] === '1') {
            spl_autoload_register(function (string $class) use ($argv): void {
                if (str_starts_with($class, 'Studeo\\Blog\\') && is_file($file = $argv[2].'/Blog/'.str_replace('\\', '/', substr($class, 12)).'.php')) {
                    require $file;
                }
            });
        }
        $handler = new class implements Illuminate\Contracts\Debug\ExceptionHandler {
            public array $reported = [];
            public function report(Throwable $e) { $this->reported[] = $e->getMessage(); }
            public function shouldReport(Throwable $e) { return true; }
            public function render($request, Throwable $e) { return new Symfony\Component\HttpFoundation\Response; }
            public function renderForConsole($output, Throwable $e) {}
        };
        $app = Orchestra\Testbench\Foundation\Application::create();
        $app->instance(Illuminate\Contracts\Debug\ExceptionHandler::class, $handler);
        $app->instance(Illuminate\Support\Composer::class, new class($app['files']) extends Illuminate\Support\Composer {
            public function dumpAutoloads($extra = '', $composerBinary = null) { return 0; }
        });
        $app['config']->set('modular.root_path', $argv[2]);
        $app['config']->set('modular.root_namespace', 'Studeo');
        $app['config']->set('app.debug', $argv[5] === '1');
        $argv[3] === 'http' && (new ReflectionProperty($app, 'isRunningInConsole'))->setValue($app, false);
        $result = ['thrown' => null, 'list' => null, 'uninstall' => null];
        try {
            $app->register(Studeo\Modular\Providers\ModularServiceProvider::class);
            if ($argv[3] === 'console') {
                $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class)->rerouteSymfonyCommandEvents();
                $result['list'] = [$kernel->call('list'), $kernel->output()];
                $result['uninstall'] = [$kernel->call('module:uninstall', ['module' => 'Blog']), $kernel->output()];
            }
        } catch (Throwable $e) {
            $result['thrown'] = get_class($e).': '.$e->getMessage();
        }
        $result['registered'] = $app->getProvider('Studeo\\Blog\\BlogServiceProvider') === null ? null : 'Studeo\\Blog\\BlogServiceProvider';
        $result['reported'] = $handler->reported;
        echo json_encode($result);
        PHP;

    $process = new Process([PHP_BINARY, '-r', $script, dirname(__DIR__, 2).'/vendor/autoload.php', $test->modulesPath, $mode, $autoload ? '1' : '0', $debug ? '1' : '0']);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

function installedModules(object $test): array
{
    return array_keys(json_decode(File::get($test->composerPath), true)['autoload']['psr-4']);
}

it('keeps artisan working when the autoloader is stale, warns, and lets module:uninstall repair it', function () {
    $result = bootedProcess($this, 'console');

    expect($result['thrown'])->toBeNull()
        ->and($result['registered'])->toBeNull()
        ->and($result['list'][0])->toBe(0)
        ->and($result['list'][1])->toContain('Module [Blog] failed to load', 'composer dump-autoload', 'Studeo\\Blog\\BlogServiceProvider')
        ->and($result['uninstall'][0])->toBe(0)
        ->and($result['uninstall'][1])->toContain('Module [Blog] failed to load', 'uninstalled successfully')
        ->and(installedModules($this))->toBe(['App\\'])
        ->and($this->rootAppSnapshot())->toBe($this->rootAppBefore);
});

it('keeps artisan working when the provider namespace does not match the manifest, and says what must match', function () {
    $provider = $this->modulesPath.'/Blog/BlogServiceProvider.php';
    File::put($provider, str_replace('namespace Studeo\\Blog;', 'namespace Acme\\Blog;', File::get($provider)));

    $result = bootedProcess($this, 'console', autoload: true);

    expect($result['thrown'])->toBeNull()
        ->and($result['registered'])->toBeNull()
        ->and($result['list'][0])->toBe(0)
        ->and($result['list'][1])->toContain('Module [Blog] failed to load', 'Acme\\Blog', 'must equal', 'Studeo\\Blog', 'composer dump-autoload')
        ->and($result['uninstall'][0])->toBe(0)
        ->and($result['uninstall'][1])->toContain('uninstalled successfully')
        ->and(installedModules($this))->toBe(['App\\'])
        ->and($this->rootAppSnapshot())->toBe($this->rootAppBefore);
});

it('reports the unloadable provider over HTTP and throws only in debug', function () {
    $quiet = bootedProcess($this, 'http');
    $debug = bootedProcess($this, 'http', debug: true);

    expect($quiet['thrown'])->toBeNull()
        ->and($quiet['registered'])->toBeNull()
        ->and($quiet['reported'])->toHaveCount(1)
        ->and($quiet['reported'][0])->toContain('Module [Blog] failed to load', 'composer dump-autoload')
        ->and($debug['thrown'])->toStartWith('Studeo\\Modular\\Exceptions\\ModuleLoadException: Module [Blog] failed to load')
        ->and($this->rootAppSnapshot())->toBe($this->rootAppBefore);
});

it('registers the provider as before when it loads', function () {
    $result = bootedProcess($this, 'console', autoload: true);

    expect($result['thrown'])->toBeNull()
        ->and($result['registered'])->toBe('Studeo\\Blog\\BlogServiceProvider')
        ->and($result['list'][1])->not->toContain('failed to load');
});

it('does not mask an error inside the provider file as a missing class', function () {
    $provider = $this->modulesPath.'/Blog/BlogServiceProvider.php';
    File::put($provider, str_replace('extends ServiceProvider', 'extends MissingBaseProvider', File::get($provider)));

    $result = bootedProcess($this, 'http', autoload: true);

    expect($result['thrown'])->toContain('MissingBaseProvider')
        ->and($result['reported'])->toBe([]);
});
