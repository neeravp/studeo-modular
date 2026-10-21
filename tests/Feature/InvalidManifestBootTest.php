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
 * Boots a fresh console application over the installed Blog module, runs `list` and
 * `module:uninstall Blog` and reports whether anything was thrown.
 *
 * @return array{thrown: ?string, list: array{int, string}, uninstall: array{int, string}}
 */
function bootWithManifest(object $test): array
{
    $script = <<<'PHP'
        require $argv[1];
        $app = Orchestra\Testbench\Foundation\Application::create();
        $app->instance(Illuminate\Support\Composer::class, new class($app['files']) extends Illuminate\Support\Composer {
            public function dumpAutoloads($extra = '', $composerBinary = null) { return 0; }
        });
        $app['config']->set('modular.root_path', $argv[2]);
        $result = ['thrown' => null, 'list' => null, 'uninstall' => null];
        try {
            $app->register(Studeo\Modular\Providers\ModularServiceProvider::class);
            $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class)->rerouteSymfonyCommandEvents();
            $result['list'] = [$kernel->call('list'), $kernel->output()];
            $result['uninstall'] = [$kernel->call('module:uninstall', ['module' => 'Blog']), $kernel->output()];
        } catch (Throwable $e) {
            $result['thrown'] = get_class($e).': '.$e->getMessage();
        }
        echo json_encode($result);
        PHP;

    $process = new Process([PHP_BINARY, '-r', $script, dirname(__DIR__, 2).'/vendor/autoload.php', $test->modulesPath]);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

it('treats an installed manifest with a reserved namespace as a load failure, not a fatal', function (string $namespace, string $segment) {
    $this->writeModuleManifest($this->modulesPath.'/Blog', 'Blog', $namespace);

    $result = bootWithManifest($this);

    expect($result['thrown'])->toBeNull()
        ->and($result['list'][0])->toBe(0)
        ->and($result['list'][1])->toContain('Module [Blog] failed to load', "[{$segment}]")
        ->and($result['uninstall'][0])->toBe(0)
        ->and(array_keys(json_decode(File::get($this->composerPath), true)['autoload']['psr-4']))->toBe(['App\\'])
        ->and($this->rootAppSnapshot())->toBe($this->rootAppBefore);
})->with([
    'a vendor prefix' => ['Illuminate\\Blog', 'Illuminate'],
    'an app prefix' => ['App\\Blog', 'App'],
]);

it('treats an installed manifest with a malformed namespace as a load failure', function () {
    $this->writeModuleManifest($this->modulesPath.'/Blog', 'Blog', 'Studeo\\\\Blog');

    $result = bootWithManifest($this);

    expect($result['thrown'])->toBeNull()
        ->and($result['list'][1])->toContain('Module [Blog] failed to load', 'empty segment');
});
