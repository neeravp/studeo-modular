<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Tests\TestCase;
use Symfony\Component\Process\Process;

uses(TestCase::class);

/**
 * The real command in a fresh PHP process whose stdin is not a terminal. In-process tests cannot
 * show this: Laravel counts a unit test as promptable, so the stdin rule is only visible here,
 * and only outside the `testing` environment.
 */
function pickerWithoutTerminal(string $workingPath, array $arguments): Process
{
    $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/vendor/bin/testbench', ...$arguments], $workingPath, ['APP_ENV' => 'local']);
    $process->setTimeout(60);
    $process->setInput('');
    $process->run();

    return $process;
}

beforeEach(function () {
    $this->work = installerRoot([
        'testbench.yaml' => "providers:\n  - Studeo\\Modular\\Providers\\ModularServiceProvider\n",
        'vendor/autoload.php' => "<?php require '".dirname(__DIR__, 2)."/vendor/autoload.php';",
    ]);
    $this->rootPath = config('modular.root_path');
    File::deleteDirectory($this->rootPath);

    foreach (['Account', 'Billing'] as $name) {
        $this->writeModuleManifest("{$this->rootPath}/{$name}", $name, "Studeo\\{$name}");
    }
});

afterEach(function () {
    File::deleteDirectory($this->rootPath);
    cleanInstallerRoots();
});

it('fails naming --module, and writes nothing, when several modules exist and stdin is not a terminal', function () {
    $process = pickerWithoutTerminal($this->work, ['module:event', 'OrderPlaced']);
    $output = $process->getOutput().$process->getErrorOutput();

    expect($process->getExitCode())->toBe(1, $output)
        ->and($output)->toContain('--module')->toContain('Account, Billing')
        ->and("{$this->rootPath}/Account/Events")->not->toBeDirectory()
        ->and("{$this->rootPath}/Billing/Events")->not->toBeDirectory();
});

it('lets --module through when stdin is not a terminal', function () {
    $process = pickerWithoutTerminal($this->work, ['module:event', 'OrderPlaced', '--module=Billing']);
    $output = $process->getOutput().$process->getErrorOutput();

    expect($process->getExitCode())->toBe(0, $output)
        ->and("{$this->rootPath}/Billing/Events/OrderPlaced.php")->toBeFile();
});
