<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Tests\TestCase;
use Symfony\Component\Process\Process;

uses(TestCase::class);

/**
 * The real command in a fresh PHP process whose stdin is not a terminal. In-process tests cannot
 * show this: Laravel counts a unit test as promptable, so the stdin rule is only visible here.
 */
function runWithoutTerminal(string $workingPath, array $arguments): Process
{
    $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/vendor/bin/testbench', ...$arguments], $workingPath);
    $process->setTimeout(120);
    $process->setEnv(['APP_ENV' => false]);
    $process->run();

    return $process;
}

beforeEach(function () {
    $this->work = installerRoot([
        'testbench.yaml' => "providers:\n  - Studeo\\Modular\\Providers\\ModularServiceProvider\n",
        'vendor/autoload.php' => "<?php require '".dirname(__DIR__, 2)."/vendor/autoload.php';",
    ]);
});

afterEach(fn () => cleanInstallerRoots());

it('prints the instructions and writes nothing when stdin is not a terminal', function () {
    $before = array_keys(frontendSnapshot($this->work));
    $repository = [md5_file($packageJson = dirname(__DIR__, 2).'/package.json'), md5_file($lockfile = dirname(__DIR__, 2).'/package-lock.json')];

    $process = runWithoutTerminal($this->work, ['module:vue', 'PostCard', '--module=Blog']);
    $output = $process->getOutput().$process->getErrorOutput();

    expect($process->getExitCode())->toBe(1, $output)
        ->and($output)->toContain('npm install vue@^3.5 @inertiajs/vue3@^3.0 @vitejs/plugin-vue unplugin-vue-components')
        ->and($output)->not->toContain('Installing Inertia 3 and Vue')
        ->and([md5_file($packageJson), md5_file($lockfile)])->toBe($repository)
        ->and(array_keys(frontendSnapshot($this->work)))->toBe($before)
        ->and(collect(File::allFiles($this->work, true))->map->getFilename()->all())
        ->not->toContain('package.json')->not->toContain('vite.config.js')->not->toContain('app.blade.php');
});
