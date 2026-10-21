<?php

use Illuminate\Support\Composer;
use Illuminate\Support\Facades\File;
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
 * Boots a fresh application in a PHP process that hides Livewire from the autoloader,
 * so Livewire is genuinely absent, and reports what happened.
 *
 * @return array{livewire: bool, failures: int, middleware: bool}
 */
function bootInProcessWithoutLivewire(string $modulesPath): array
{
    $script = <<<'PHP'
        $loader = require $argv[1];
        // Livewire is a dev dependency here, so hide it: only this autoloader remains, and it refuses Livewire\\ classes.
        $loader->unregister();
        spl_autoload_register(fn (string $class) => str_starts_with($class, 'Livewire\\') ? null : $loader->loadClass($class));
        spl_autoload_register(function (string $class) use ($argv): void {
            $file = $argv[2].'/'.str_replace('\\', '/', substr($class, strlen('Studeo\\Absent\\'))).'.php';
            if (str_starts_with($class, 'Studeo\\Absent\\') && is_file($file)) {
                require $file;
            }
        });
        $app = Orchestra\Testbench\Foundation\Application::create();
        $app['config']->set('modular.root_path', $argv[3]);
        $app['config']->set('modular.root_namespace', 'Studeo');
        $app->register(Studeo\Modular\Providers\ModularServiceProvider::class);
        echo json_encode([
            'livewire' => class_exists('Livewire\\Livewire', false),
            'failures' => count($app->make(Studeo\Modular\Support\ModuleLoadFailures::class)->all()),
            'middleware' => ($app['router']->getMiddleware()['absent.ensure-active'] ?? null) === 'Studeo\\Absent\\Middleware\\EnsureActive',
        ]);
        PHP;

    $process = new Process([
        PHP_BINARY, '-r', $script,
        dirname(__DIR__, 2).'/vendor/autoload.php',
        $modulesPath.'/Absent',
        $modulesPath,
    ]);
    $process->run();

    return json_decode($process->getOutput(), true) ?? ['error' => $process->getOutput().$process->getErrorOutput()];
}

it('skips Livewire registration without failures when Livewire is not installed, and still runs the other steps', function () {
    $this->artisan('module:make', ['module' => 'Absent'])->assertSuccessful();
    $this->artisan('module:livewire', ['name' => 'Counter', '--module' => 'Absent'])->assertSuccessful();
    File::ensureDirectoryExists($this->modulesPath.'/Absent/Middleware');
    File::put($this->modulesPath.'/Absent/Middleware/EnsureActive.php', "<?php\n\nnamespace Studeo\\Absent\\Middleware;\n\nclass EnsureActive\n{\n}\n");
    $this->artisan('module:install', ['module' => 'Absent'])->assertSuccessful();

    expect(bootInProcessWithoutLivewire($this->modulesPath))
        ->toBe(['livewire' => false, 'failures' => 0, 'middleware' => true]);
});
