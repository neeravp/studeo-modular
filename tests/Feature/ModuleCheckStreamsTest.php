<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->work = installerRoot([
        'testbench.yaml' => "providers:\n  - Studeo\\Modular\\Providers\\ModularServiceProvider\n  - Studeo\\Modular\\Tests\\CheckRules\\ConfigProvider\n",
        'vendor/autoload.php' => "<?php require '".dirname(__DIR__, 2)."/vendor/autoload.php';",
    ]);
    $this->rootPath = config('modular.root_path');
    File::deleteDirectory($this->rootPath);
    runOk($this->work, ['module:make', 'Blog']);
});

afterEach(function () {
    File::deleteDirectory($this->rootPath);
    cleanInstallerRoots();
});

it('checks every readable module with --all, then names each skipped directory on stderr and exits 2', function (array $format) {
    seedModule($this, 'Blog', ['Services/Broken.php' => "<?php\n\n\$x = ;\n"]);
    File::ensureDirectoryExists("{$this->rootPath}/Broken");
    File::put("{$this->rootPath}/Broken/module.json", '{not json');

    $process = checkCommand($this->work, ['module:check', '--all', ...$format]);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getOutput())->toContain($format === [] ? 'modules/Blog/Services/Broken.php:3' : '"line": 3')->not->toContain('Skipped')
        ->and($process->getErrorOutput())->toContain('Skipped [Broken]')->toContain('module.json');
})->with([[[]], [['--format=json']]]);

it('keeps stdout to the JSON array when --all skips a directory', function () {
    seedModule($this, 'Blog', ['Services/Broken.php' => "<?php\n\n\$x = ;\n"]);
    File::ensureDirectoryExists("{$this->rootPath}/Stray");

    $process = checkCommand($this->work, ['module:check', '--all', '--format=json']);
    $decoded = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    expect($process->getExitCode())->toBe(2)
        ->and($decoded)->toHaveCount(1)
        ->and($decoded[0]['rule'])->toBe('syntax');
});

it('prints nothing on stdout under --format=json for every exit 2 cause', function (array $arguments, array $env) {
    File::put("{$this->rootPath}/Blog/module.json", '{not json');
    checkCommand($this->work, ['module:make', 'Other']);

    $process = checkCommand($this->work, ['module:check', '--format=json', ...$arguments], $env);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())->not->toBe('');
})->with([
    'broken manifest' => [['--module=Blog'], []],
    'both options' => [['--all', '--module=Other'], []],
    'unexpected exception' => [['--module=Other'], ['CHECK_RULES' => 'Studeo\\Modular\\Tests\\CheckRules\\Explodes']],
    'invalid rule set' => [['--module=Other'], ['CHECK_RULES' => 'Studeo\\Modular\\Tests\\CheckRules\\BadOptions']],
]);

it('sends the module announcement to stderr under --format=json', function () {
    $process = checkCommand($this->work, ['module:check', '--format=json']);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->not->toContain('Using module')
        ->and($process->getErrorOutput())->toContain('Using module [Blog].');
});
