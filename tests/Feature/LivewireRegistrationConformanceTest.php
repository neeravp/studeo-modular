<?php

use Illuminate\Support\Composer;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Tests\TestCase;
use Symfony\Component\Process\Process;

uses(TestCase::class);

beforeEach(function () {
    $this->rootComposerPath = base_path('composer.json');
    $this->originalRootComposer = File::exists($this->rootComposerPath) ? File::get($this->rootComposerPath) : null;
    File::put($this->rootComposerPath, json_encode(['name' => 'laravel/laravel', 'autoload' => ['psr-4' => ['App\\' => 'app/']]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

    $this->modulesPath = config('modular.root_path');
    File::deleteDirectory($this->modulesPath);
    File::ensureDirectoryExists($this->modulesPath);

    $composer = Mockery::mock(Composer::class);
    $composer->shouldReceive('dumpAutoloads')->andReturn(0);
    $this->app->instance(Composer::class, $composer);

    $this->artisan('module:make', ['module' => 'BlogPosts'])->assertSuccessful();
    foreach ([['Counter', '--classic'], ['Posts/Row', '--classic'], ['Gauge', '--mfc'], ['Quick', '--sfc'], ['Posts/Create', '--sfc']] as [$name, $type]) {
        $this->artisan('module:livewire', ['name' => $name, '--module' => 'BlogPosts', $type => true])->assertSuccessful();
    }
    $this->artisan('module:install', ['module' => 'BlogPosts'])->assertSuccessful();
    $this->module = $this->modulesPath.'/BlogPosts';
    $this->rootAppBefore = $this->rootAppSnapshot();
});

afterEach(function () {
    File::deleteDirectory($this->modulesPath);

    $this->originalRootComposer !== null
        ? File::put($this->rootComposerPath, $this->originalRootComposer)
        : File::delete($this->rootComposerPath);
});

/**
 * Boot an application with the real Livewire 4 and the installed module in a fresh process, render
 * each alias through Livewire::test, and run the generated test files' own code.
 *
 * @param  array<int, string>  $aliases
 * @param  array<int, string>  $testFiles
 * @return array{renders: array<string, string>, tests: array<string, string>}
 */
function bootWithLivewire(object $test, array $aliases, array $testFiles): array
{
    $script = <<<'PHP'
        require $argv[1];
        spl_autoload_register(function (string $class) use ($argv): void {
            $file = $argv[2].'/'.str_replace('\\', '/', substr($class, strlen('Studeo\\BlogPosts\\'))).'.php';
            if (str_starts_with($class, 'Studeo\\BlogPosts\\') && is_file($file)) {
                require $file;
            }
        });
        $app = Orchestra\Testbench\Foundation\Application::create();
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('modular.root_path', $argv[3]);
        $app['config']->set('modular.root_namespace', 'Studeo');
        $app->register(Livewire\LivewireServiceProvider::class);
        $app->register(Studeo\Modular\Providers\ModularServiceProvider::class);
        $outcome = function (Closure $run): string {
            try {
                $run();

                return 'ok';
            } catch (Throwable $exception) {
                return class_basename($exception).': '.substr($exception->getMessage(), 0, 120);
            }
        };
        $renders = [];
        foreach (json_decode($argv[4], true) as $alias) {
            $renders[$alias] = $outcome(fn () => Livewire\Livewire::test($alias)->assertStatus(200));
        }
        $its = [];
        function it(string $description, Closure $body): void { $GLOBALS['its'][] = $body; }
        $tests = [];
        foreach (json_decode($argv[5], true) as $file) {
            $GLOBALS['its'] = [];
            require $file;
            $tests[$file] = $GLOBALS['its'] === [] ? 'no tests' : implode(',', array_map(fn ($body) => $outcome($body), $GLOBALS['its']));
        }
        echo json_encode(['renders' => $renders, 'tests' => $tests]);
        PHP;

    $process = new Process([PHP_BINARY, '-r', $script, dirname(__DIR__, 2).'/vendor/autoload.php', $test->module, $test->modulesPath, json_encode($aliases), json_encode($testFiles)]);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

it('renders a classic, an MFC and an SFC component, nested ones too, through their dot aliases', function () {
    $aliases = ['blog-posts.counter', 'blog-posts.posts.row', 'blog-posts.gauge', 'blog-posts.quick', 'blog-posts.posts.create'];

    $result = bootWithLivewire($this, $aliases, []);

    expect($result['renders'])->toBe(array_fill_keys($aliases, 'ok'));
});

it('also resolves the :: form for the top-level components', function () {
    $aliases = ['blog-posts::counter', 'blog-posts::gauge', 'blog-posts::quick'];

    expect(bootWithLivewire($this, $aliases, [])['renders'])->toBe(array_fill_keys($aliases, 'ok'));
});

it('generates test files that pass, address the dot alias and import nothing from the module', function () {
    $files = [
        $this->module.'/tests/Feature/Livewire/CounterTest.php' => 'blog-posts.counter',
        $this->module.'/tests/Feature/Livewire/Posts/RowTest.php' => 'blog-posts.posts.row',
        $this->module.'/tests/Feature/Livewire/QuickTest.php' => 'blog-posts.quick',
        $this->module.'/tests/Feature/Livewire/Posts/CreateTest.php' => 'blog-posts.posts.create',
        $this->module.'/Livewire/⚡gauge/gauge.test.php' => 'blog-posts.gauge',
    ];

    foreach ($files as $file => $alias) {
        expect(File::get($file))->toContain("Livewire::test('{$alias}')")->not->toContain('use Studeo')->not->toContain('::class')->not->toContain('//');
    }

    expect(bootWithLivewire($this, [], array_keys($files))['tests'])->toBe(array_fill_keys(array_keys($files), 'ok'));
});

it('writes only inside the module directory', function () {
    expect($this->rootAppSnapshot())->toBe($this->rootAppBefore)
        ->and(File::exists($this->module.'/Livewire/⚡gauge/gauge.php'))->toBeTrue()
        ->and(File::exists($this->module.'/Livewire/Gauge'))->toBeFalse();
});
