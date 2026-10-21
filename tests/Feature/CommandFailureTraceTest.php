<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Tests\TestCase;
use Symfony\Component\Process\Process;

uses(TestCase::class);

beforeEach(function () {
    $this->rootModulesPath = config('modular.root_path');
    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);
    $this->artisan('module:make', ['module' => 'Blog'])->assertSuccessful();
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

/**
 * Runs a real command in a fresh process and returns its exit code and what it printed.
 *
 * @param  array<string, mixed>  $arguments
 * @return array{code: int, output: string}
 */
function failingRun(object $test, string $command, array $arguments, ?int $verbosity = null): array
{
    $script = <<<'PHP'
        require $argv[1];
        $app = Orchestra\Testbench\Foundation\Application::create();
        $app['config']->set('modular.root_path', $argv[2]);
        $app->register(Studeo\Modular\Providers\ModularServiceProvider::class);
        $app->boot();
        $output = new Symfony\Component\Console\Output\BufferedOutput(json_decode($argv[5], true) ?? Symfony\Component\Console\Output\OutputInterface::VERBOSITY_NORMAL);
        $code = Illuminate\Support\Facades\Artisan::call($argv[3], json_decode($argv[4], true), $output);
        echo json_encode(['code' => $code, 'output' => $output->fetch()]);
        PHP;

    $process = new Process([PHP_BINARY, '-r', $script, dirname(__DIR__, 2).'/vendor/autoload.php', $test->rootModulesPath, $command, json_encode($arguments), json_encode($verbosity)]);
    $process->setInput(null);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

dataset('failing commands', [
    'module:action (ModuleCommand)' => ['module:action', ['name' => 'Bare', '--module' => 'Blog', '--schema' => 'nonsense without a field']],
    'module:controller (ModuleCommand)' => ['module:controller', ['name' => 'Post', '--module' => 'Blog', '--model' => 'Post']],
    'module:enum (ModuleCommand)' => ['module:enum', ['name' => 'Status', '--module' => 'Blog', '--cases' => 'Bad Case']],
    'module:vue:delete (ModuleCommand, match)' => ['module:vue:delete', ['name' => 'Missing', '--module' => 'Blog']],
    'module:livewire:move (ModuleCommand, match)' => ['module:livewire:move', ['name' => 'Missing', 'new-name' => 'Other', '--module' => 'Blog']],
    'module:make (Command)' => ['module:make', ['module' => '1 not valid']],
    'module:install (Command)' => ['module:install', ['module' => 'Missing']],
    'module:uninstall (Command)' => ['module:uninstall', ['module' => '1 not valid']],
    'module:remove (Command)' => ['module:remove', ['module' => '1 not valid']],
]);

it('prints one error line and no trace at normal verbosity', function (string $command, array $arguments) {
    $run = failingRun($this, $command, $arguments);

    expect($run['code'])->toBe(1)->and($run['output'])->not->toBe('')->not->toContain('#0 ');
})->with('failing commands');

it('prints the stack trace after the error under -v, with the same exit code', function (string $command, array $arguments) {
    $quiet = failingRun($this, $command, $arguments);
    $verbose = failingRun($this, $command, $arguments, 64);

    expect($verbose['code'])->toBe($quiet['code'])
        ->and($verbose['output'])->toContain('#0 ')->toContain('{main}')
        ->and(str_starts_with($verbose['output'], $quiet['output']))->toBeTrue();
})->with('failing commands');

it('prints nothing under --quiet, trace included', function () {
    $run = failingRun($this, 'module:enum', ['name' => 'Status', '--module' => 'Blog', '--cases' => 'Bad Case'], 16);

    expect($run['code'])->toBe(1)->and($run['output'])->toBe('');
});

it('routes every command-level catch of Throwable through the shared trace helper', function () {
    $offenders = collect(File::glob(dirname(__DIR__, 2).'/Console/*.php'))
        ->filter(fn (string $file) => str_contains(File::get($file), 'catch (Throwable $exception)'))
        ->reject(fn (string $file) => str_contains(File::get($file), 'reportFailure($exception)'))
        ->map(fn (string $file) => basename($file))->values()->all();

    expect($offenders)->toBe([]);
});
