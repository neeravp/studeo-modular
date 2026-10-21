<?php

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Orchestra\Testbench\Foundation\Console\Kernel as ConsoleKernel;
use Studeo\Modular\Support\ModuleCache;
use Studeo\Modular\Tests\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

uses(TestCase::class);

beforeEach(function () {
    $this->scratch = sys_get_temp_dir().'/modular-cache-failure-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->scratch);
    File::delete($this->moduleCachePath());

    $this->failBuild = function (): void {
        $this->app->instance(Filesystem::class, new class extends Filesystem
        {
            public function isFile($file): bool
            {
                throw new RuntimeException('The build could not read the composer file.');
            }
        });
        $this->app->forgetInstance(ModuleCache::class);
    };
    $this->failWrite = function (): void {
        File::ensureDirectoryExists($this->scratch.'/modular.php');
        $this->app->instance(ModuleCache::class, new ModuleCache($this->scratch.'/modular.php', new Filesystem));
    };
    $this->run = function (array $input): array {
        $output = new BufferedOutput;
        $status = Artisan::call('modular:cache', $input, $output);

        return [$status, $output->fetch()];
    };
    $this->optimize = function (array $input): array {
        $output = new BufferedOutput;
        $kernel = new class($this->app, $this->app->make(Dispatcher::class)) extends ConsoleKernel {};
        $status = $kernel->handle(
            new ArrayInput(['command' => 'optimize', '--except' => 'config,events,routes,views'] + $input),
            $output
        );

        return [$status, $output->fetch()];
    };
    $this->tempFiles = fn (): array => array_values(array_filter(
        scandir(sys_get_temp_dir()),
        fn (string $name): bool => preg_match('/^modular[A-Za-z0-9]{6}$/', $name) === 1
    ));
});

afterEach(function () {
    File::deleteDirectory($this->scratch);
});

it('reports a failing build with its message and exit 1, and writes no cache file', function () {
    ($this->failBuild)();

    [$status, $display] = ($this->run)([]);

    expect($status)->toBe(1)
        ->and($display)->toContain('The build could not read the composer file.')->not->toContain('#0 ')
        ->and(file_exists($this->moduleCachePath()))->toBeFalse();
});

it('reports a failing write with its message and exit 1, and leaves no cache file and no temp file', function () {
    ($this->failWrite)();

    [$status, $display] = ($this->run)([]);

    expect($status)->toBe(1)
        ->and($display)->toContain('Unable to write the module cache')->not->toContain('rename(')->not->toContain('#0 ')
        ->and(is_dir($this->scratch.'/modular.php'))->toBeTrue()
        ->and(array_values(array_diff(scandir($this->scratch), ['.', '..'])))->toBe(['modular.php']);
});

it('prints the stack trace after the message under -v, with the same exit code', function (string $failure, string $message) {
    ($this->{$failure})();
    [$plainStatus] = ($this->run)([]);
    [$status, $display] = ($this->run)(['-v' => true]);

    expect($status)->toBe($plainStatus)->toBe(1)
        ->and($display)->toContain($message)->toContain('{main}')
        ->and(strpos($display, '#0 '))->toBeGreaterThan(strpos($display, $message));
})->with([
    'a failing build' => ['failBuild', 'The build could not read the composer file.'],
    'a failing write' => ['failWrite', 'Unable to write the module cache'],
]);

it('prints nothing under --quiet', function (string $failure) {
    ($this->{$failure})();

    [$status, $display] = ($this->run)(['--quiet' => true]);

    expect($status)->toBe(1)->and($display)->toBe('');
})->with(['a failing build' => 'failBuild', 'a failing write' => 'failWrite']);

it('leaves no temp file in the system temp directory when the cache directory cannot hold one', function () {
    $before = ($this->tempFiles)();
    $cache = new ModuleCache($this->scratch.'/missing/modular.php', new class extends Filesystem
    {
        public function ensureDirectoryExists($path, $mode = 0755, $recursive = true): void {}
    });

    expect(fn () => $cache->write([]))->toThrow(RuntimeException::class, 'Unable to write the module cache')
        ->and(($this->tempFiles)())->toBe($before)
        ->and(file_exists($this->scratch.'/missing'))->toBeFalse();
});

it('shows a failing modular:cache from optimize, marks the step failed and exits 1', function (string $failure, string $message) {
    ($this->{$failure})();

    [$status, $display] = ($this->optimize)([]);

    expect($status)->toBe(1)
        ->and($display)->toContain($message)->toContain('FAIL')->not->toMatch('/modular .*DONE/')
        ->and(file_exists($this->moduleCachePath()))->toBeFalse();
})->with([
    'a failing build' => ['failBuild', 'The build could not read the composer file.'],
    'a failing write' => ['failWrite', 'Unable to write the module cache'],
]);

it('prints the stack trace of the failing step under -v and none otherwise, and still exits 1 without the step lines under --quiet', function () {
    ($this->failBuild)();

    [$plainStatus, $plain] = ($this->optimize)([]);
    [$status, $display] = ($this->optimize)(['-v' => true]);
    [$quietStatus, $quiet] = ($this->optimize)(['--quiet' => true]);

    expect($plainStatus)->toBe($status)->toBe($quietStatus)->toBe(1)
        ->and($plain)->not->toContain('ModularOptimizeCommand->')
        ->and($display)->toContain('ModularOptimizeCommand->')
        ->and($quiet)->not->toContain('FAIL')->not->toContain('INFO')->not->toContain('ModularOptimizeCommand->');
});

it('still prints DONE for the modular step and exits 0 when the cache is written', function () {
    [$status, $display] = ($this->optimize)([]);

    expect($status)->toBe(0)->and($display)->toMatch('/modular .*DONE/')
        ->and(file_exists($this->moduleCachePath()))->toBeTrue();
});

it('exits 1 with the message in a fresh process when the cache directory cannot be written', function () {
    $bootstrap = $this->scratch.'/bootstrap';
    File::ensureDirectoryExists($bootstrap.'/cache');
    chmod($bootstrap.'/cache', 0555);
    $this->skip = ! is_writable($bootstrap.'/cache') ? null : 'the directory stays writable for this user';

    if ($this->skip !== null) {
        chmod($bootstrap.'/cache', 0755);
        $this->markTestSkipped($this->skip);
    }

    $script = <<<'PHP'
        require $argv[1];
        $app = Orchestra\Testbench\Foundation\Application::create();
        $app->useBootstrapPath($argv[2]);
        $app->register(Studeo\Modular\Providers\ModularServiceProvider::class);
        $app->boot();
        $output = new Symfony\Component\Console\Output\BufferedOutput;
        $code = Illuminate\Support\Facades\Artisan::call('modular:cache', [], $output);
        echo json_encode(['code' => $code, 'output' => $output->fetch()]);
        PHP;
    $tempBefore = ($this->tempFiles)();
    $process = new Process([PHP_BINARY, '-r', $script, dirname(__DIR__, 2).'/vendor/autoload.php', $bootstrap]);
    $process->setInput(null);
    $process->run();
    chmod($bootstrap.'/cache', 0755);
    $result = json_decode($process->getOutput(), true);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($result['code'])->toBe(1)
        ->and($result['output'])->toContain('Unable to write the module cache')->not->toContain('tempnam')->not->toContain('#0 ')
        ->and(file_exists($bootstrap.'/cache/modular.php'))->toBeFalse()
        ->and(array_diff(scandir($bootstrap.'/cache'), ['.', '..']))->toBe([])
        ->and(($this->tempFiles)())->toBe($tempBefore);
});
