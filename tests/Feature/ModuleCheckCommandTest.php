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

it('exits 0 for a clean generated module', function () {
    foreach ([['module:action', 'CreatePost', '--schema=title:string'], ['module:model', 'Post'], ['module:dto', 'PostData']] as $command) {
        runOk($this->work, [...$command, '--module=Blog']);
    }

    $process = checkCommand($this->work, ['module:check', '--module=Blog']);

    expect($process->getExitCode())->toBe(0, checkOutput($process))
        ->and(trim($process->getOutput()))->toEndWith('0 errors, 0 warnings.');
});

it('reports one seeded violation per rule as path:line [rule] and exits 1', function (string $path, string $contents, string $expected) {
    seedModule($this, 'Blog', [$path => $contents]);
    runOk($this->work, ['module:make', 'Account']);

    $process = checkCommand($this->work, ['module:check', '--module=Blog']);
    $lines = explode("\n", $process->getOutput());

    expect($process->getExitCode())->toBe(1, checkOutput($process))
        ->and(array_values(array_filter($lines, fn (string $line): bool => str_starts_with($line, $expected))))->toHaveCount(1)
        ->and(trim($process->getOutput()))->toContain('1 error');
})->with([
    'method-complexity' => ['Services/Seeded.php', "<?php\n\nnamespace Blog\\Services;\n\nfinal class Seeded\n{\n    public function go(\$a, \$b, \$c)\n    {\n        if (\$a) {\n            return 1;\n        }\n        if (\$b) {\n            return 2;\n        }\n        if (\$c) {\n            return 3;\n        }\n\n        return 0;\n    }\n}\n", 'modules/Blog/Services/Seeded.php:7 [method-complexity]'],
    'file-length' => ['Services/Long.php', longFile(301), 'modules/Blog/Services/Long.php:1 [file-length]'],
    'method-naming' => ['Models/Seeded.php', "<?php\n\nnamespace Blog\\Models;\n\nfinal class Seeded\n{\n    public function doStuff(): void {}\n}\n", 'modules/Blog/Models/Seeded.php:7 [method-naming]'],
    'module-isolation' => ['Models/Seeded.php', "<?php\n\nnamespace Blog\\Models;\n\nuse Account\\Models\\User;\n\nfinal class Seeded {}\n", 'modules/Blog/Models/Seeded.php:5 [module-isolation]'],
    'action-shape' => ['Actions/SeededAction.php', "<?php\n\nnamespace Blog\\Actions;\n\nuse Illuminate\\Contracts\\Auth\\Authenticatable;\nuse Illuminate\\Support\\Facades\\Gate;\n\nclass SeededAction\n{\n    public function __invoke(Authenticatable \$actor): void\n    {\n        Gate::forUser(\$actor)->authorize('seeded');\n    }\n}\n", 'modules/Blog/Actions/SeededAction.php:8 [action-shape]'],
    'dto-shape' => ['Data/SeededData.php', "<?php\n\nnamespace Blog\\Data;\n\nuse Studeo\\Modular\\Support\\OutputData;\n\nfinal class SeededData extends OutputData\n{\n    public string \$title = '';\n}\n", 'modules/Blog/Data/SeededData.php:7 [dto-shape]'],
    'migration' => ['database/migrations/2026_01_01_000000_seeded.php', "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\n\nreturn new class extends Migration\n{\n    public function up(): void\n    {\n        \$table->enum('a', []);\n    }\n};\n", 'modules/Blog/database/migrations/2026_01_01_000000_seeded.php:9 [migration]'],
    'flat-modules' => ['Http/Seeded.php', "<?php\n\nnamespace Blog\\Http;\n\nfinal class Seeded {}\n", 'modules/Blog/Http:1 [flat-modules]'],
    'syntax' => ['Services/Broken.php', "<?php\n\n\$x = ;\n", 'modules/Blog/Services/Broken.php:3 [syntax]'],
]);

it('keeps checking other files after one does not parse', function () {
    seedModule($this, 'Blog', ['Services/Broken.php' => "<?php\n\n\$x = ;\n", 'Models/Seeded.php' => "<?php\n\nnamespace Blog\\Models;\n\nfinal class Seeded\n{\n    public function doWork(): void {}\n}\n"]);

    $process = checkCommand($this->work, ['module:check', '--module=Blog']);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toContain('[syntax]')->toContain('[method-naming]')->toContain('2 errors');
});

it('exits 0 for a module with only warnings', function () {
    seedModule($this, 'Blog', ['Services/Long.php' => longFile(240)]);

    $process = checkCommand($this->work, ['module:check', '--module=Blog']);

    expect($process->getExitCode())->toBe(0, checkOutput($process))
        ->and($process->getOutput())->toContain('modules/Blog/Services/Long.php:1 [file-length]')->toContain('0 errors, 1 warning.');
});

it('prints only a JSON array with --format=json, even when the module is picked', function () {
    seedModule($this, 'Blog', ['Models/Seeded.php' => "<?php\n\nnamespace Blog\\Models;\n\nfinal class Seeded\n{\n    public function doWork(): void {}\n}\n"]);

    $process = checkCommand($this->work, ['module:check', '--format=json']);
    $decoded = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    expect($process->getExitCode())->toBe(1, checkOutput($process))
        ->and($decoded)->toHaveCount(1)
        ->and($decoded[0])->toMatchArray(['rule' => 'method-naming', 'file' => 'modules/Blog/Models/Seeded.php', 'line' => 7, 'subject' => 'Seeded::doWork', 'severity' => 'error'])
        ->and(array_keys($decoded[0]))->toBe(['rule', 'file', 'line', 'subject', 'severity', 'message'])
        ->and($decoded[0]['message'])->toContain('doWork');
});

it('prints an empty JSON array for a clean module', function () {
    $process = checkCommand($this->work, ['module:check', '--module=Blog', '--format=json']);

    expect($process->getExitCode())->toBe(0)
        ->and(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe([]);
});

it('checks every module with --all', function () {
    runOk($this->work, ['module:make', 'Account']);
    seedModule($this, 'Blog', ['Services/Broken.php' => "<?php\n\n\$x = ;\n"]);
    seedModule($this, 'Account', ['Services/Broken.php' => "<?php\n\n\$x = ;\n"]);

    $process = checkCommand($this->work, ['module:check', '--all']);

    expect($process->getExitCode())->toBe(1, checkOutput($process))
        ->and($process->getOutput())->toContain('modules/Blog/Services/Broken.php:3 [syntax]')->toContain('modules/Account/Services/Broken.php:3 [syntax]')->toContain('2 errors');
});

it('refuses --module together with --all', function () {
    $process = checkCommand($this->work, ['module:check', '--all', '--module=Blog']);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('--module')->toContain('--all')
        ->and($process->getOutput())->toBe('');
});

it('exits 2 for a module whose manifest cannot be read', function () {
    File::put("{$this->rootPath}/Blog/module.json", '{not json');

    $process = checkCommand($this->work, ['module:check', '--module=Blog']);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('module.json');
});

it('exits 2 when --all meets a module it cannot read', function () {
    File::ensureDirectoryExists("{$this->rootPath}/Stray");

    $process = checkCommand($this->work, ['module:check', '--all']);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('Stray');
});

it('exits 2 naming the cause for an invalid rule configuration, and leaves artisan and module:make working', function (string $rules, string $cause) {
    $env = ['CHECK_RULES' => $rules];
    $check = checkCommand($this->work, ['module:check', '--module=Blog'], $env);

    expect($check->getExitCode())->toBe(2)
        ->and($check->getErrorOutput())->toContain($cause)
        ->and($check->getOutput())->toBe('')
        ->and(checkCommand($this->work, ['list'], $env)->getExitCode())->toBe(0)
        ->and(checkCommand($this->work, ['module:make', 'Other'], $env)->getExitCode())->toBe(0)
        ->and("{$this->rootPath}/Other/module.json")->toBeFile();
})->with([
    'missing class' => ['Studeo\\Modular\\Tests\\CheckRules\\Missing', 'CheckRules\\Missing'],
    'not a rule set' => ['Studeo\\Modular\\Tests\\CheckRules\\NotARuleSet', 'CheckRules\\NotARuleSet'],
    'options rejected' => ['Studeo\\Modular\\Tests\\CheckRules\\BadOptions', 'BadOptions could not be built: Rule [file-length] option [target]'],
]);

it('exits 2, not 1, for an unexpected exception, and shows its trace under -v', function () {
    $env = ['CHECK_RULES' => 'Studeo\\Modular\\Tests\\CheckRules\\Explodes'];
    $plain = checkCommand($this->work, ['module:check', '--module=Blog'], $env);
    $verbose = checkCommand($this->work, ['module:check', '--module=Blog', '-v'], $env);

    expect($plain->getExitCode())->toBe(2)
        ->and(checkOutput($plain))->toContain('boom from the rule set')->not->toContain('#0 ')
        ->and($verbose->getExitCode())->toBe(2)
        ->and(checkOutput($verbose))->toContain('#0 ');
});
