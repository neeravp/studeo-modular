<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Composer;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Tests\Helpers\OrderedFilesystem;
use Studeo\Modular\Tests\Support\CommandDriver;
use Studeo\Modular\Tests\Support\ModulesRootFixture;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->fixture = new ModulesRootFixture([]);
    $this->composerPath = $this->fixture->composerPath;
    $this->rootPath = $this->fixture->rootPath;
    $this->fixture->writeRoot();
    $this->rootJson = File::get($this->composerPath);
    $this->modules = $this->fixture->modules(...);
    $this->drive = function (array $input, int $exit = 0, bool $throws = false, bool $ordered = false): array {
        $files = new OrderedFilesystem;
        $files->modulesRoot = $this->rootPath;
        if ($ordered) {
            app()->instance(Filesystem::class, $files);
        }
        app()->instance(Composer::class, new class($files, $exit, $throws) extends Composer
        {
            public function __construct(private readonly OrderedFilesystem $log, private readonly int $exit, private readonly bool $throws) {}

            public function dumpAutoloads($extra = '', $path = null)
            {
                $this->log->calls[] = 'composer dumpAutoloads';

                return $this->throws ? throw new RuntimeException('composer is gone') : $this->exit;
            }
        });
        $driver = new CommandDriver(app(), $this->rootPath);
        $result = $driver->run('module:install', $input);
        $driver->resetPrompts();

        return $result + ['calls' => $files->calls];
    };
    $this->spin = $this->fixture->spin(...);
});

afterEach(fn () => $this->fixture->tearDown());

it('reports a Composer exit code on stdout, exits 1 and restores composer.json, for a named module', function () {
    ($this->modules)('Account');
    $result = ($this->drive)(['module' => 'Account'], exit: 1);

    expect($result['status'])->toBe(1)
        ->and($result['stdout'])->toBe(($this->spin)('Installing module [Account]...')."Composer dump-autoload failed with exit code [1].\n")
        ->and($result['stderr'])->toBe('')
        ->and(File::get($this->composerPath))->toBe($this->rootJson);
});

it('reports a Composer exit code and restores composer.json, for the bulk install', function () {
    ($this->modules)('Account', 'Billing');
    $result = ($this->drive)(['--all' => true], exit: 1);

    expect($result['status'])->toBe(1)
        ->and($result['stdout'])->toBe(($this->spin)('Installing module(s) and dumping autoloads...')."Composer dump-autoload failed with exit code [1].\n")
        ->and(File::get($this->composerPath))->toBe($this->rootJson);
});

it('reports a throwing Composer by its message and restores composer.json', function () {
    ($this->modules)('Account');
    $result = ($this->drive)(['--all' => true], throws: true);

    expect($result['status'])->toBe(1)
        ->and($result['stdout'])->toBe(($this->spin)('Installing module(s) and dumping autoloads...')."composer is gone\n")
        ->and($result['stderr'])->toBe('')
        ->and(File::get($this->composerPath))->toBe($this->rootJson);
});

it('writes composer.json, runs Composer, then writes the original back, in that order', function () {
    ($this->modules)('Account');
    $calls = ($this->drive)(['module' => 'Account'], exit: 1, ordered: true)['calls'];

    $json = '<package>/vendor/orchestra/testbench-core/laravel/composer.json';

    expect($calls)->toBe([
        'isFile <modules>/Account/module.json true',
        'isFile <modules>/Account/module.json true',
        'get <modules>/Account/module.json',
        'isDirectory <modules>/Account true',
        'isFile <modules>/Account/module.json true',
        'isFile <modules>/Account/module.json true',
        'get <modules>/Account/module.json',
        'isFile <modules>/Account/AccountServiceProvider.php true',
        'get <modules>/Account/AccountServiceProvider.php',
        'get <modules>/Account/AccountServiceProvider.php',
        "isFile {$json} true",
        "get {$json}",
        "isFile {$json} true",
        "get {$json}",
        "put {$json}",
        'composer dumpAutoloads',
        "put {$json}",
    ]);
});

it('reads the modules and composer.json in a fixed order for the bulk install', function () {
    ($this->modules)('Account');
    $calls = ($this->drive)(['--all' => true], ordered: true)['calls'];

    $json = '<package>/vendor/orchestra/testbench-core/laravel/composer.json';

    expect(array_slice($calls, 0, 3))->toBe(['isDirectory <modules> true', 'directories <modules>', 'isFile <modules>/Account/module.json true'])
        ->and(array_slice($calls, -2))->toBe(["put {$json}", 'composer dumpAutoloads']);
});

it('installs nothing when one of several pending modules has a provider that is not usable', function () {
    ($this->modules)('Account', 'Billing');
    File::put("{$this->rootPath}/Billing/BillingServiceProvider.php", "<?php\n\nnamespace Studeo\\Billing;\n\nclass BillingServiceProvider\n{\n}\n");
    $result = ($this->drive)(['--all' => true]);

    expect($result['status'])->toBe(1)
        ->and($result['stdout'])->toBe(($this->spin)('Installing module(s) and dumping autoloads...')."Module service provider [BillingServiceProvider] must extend Illuminate\\Support\\ServiceProvider: <modules>/Billing/BillingServiceProvider.php\n")
        ->and(File::get($this->composerPath))->toBe($this->rootJson)
        ->and($result['calls'])->toBe([]);
});

it('leaves a pending module with a broken manifest out of the bulk install and lists it as skipped', function () {
    ($this->modules)('Account');
    $this->writeRawModuleManifest("{$this->rootPath}/Broken", '{not json');
    $result = ($this->drive)(['--all' => true]);

    expect($result['status'])->toBe(0)
        ->and($result['stdout'])->toBe("Skipped [Broken]: invalid module.json: Invalid module manifest <modules>/Broken/module.json: Syntax error\n".($this->spin)('Installing module(s) and dumping autoloads...')."Module [Account] installed successfully.\n")
        ->and(File::get($this->composerPath))->not->toBe($this->rootJson);
});

it('names a missing root composer.json, after the skipped lines in the bulk install and at once in a named install', function () {
    ($this->modules)('Account');
    File::delete($this->composerPath);
    File::ensureDirectoryExists("{$this->rootPath}/Bare");
    $missing = "Root composer.json not found: {$this->composerPath}\n";

    expect(($this->drive)(['--all' => true]))->toMatchArray(['status' => 1, 'stdout' => "Skipped [Bare]: no module.json.\n".str_replace(base_path(), '<base>', $missing), 'stderr' => ''])
        ->and(($this->drive)(['module' => 'Account'])['stdout'])->toBe(($this->spin)('Installing module [Account]...').str_replace(base_path(), '<base>', $missing));
});

it('names an invalid root composer.json and leaves it as it was', function () {
    ($this->modules)('Account');
    File::put($this->composerPath, '{nope');
    $result = ($this->drive)(['module' => 'Account']);

    expect($result['status'])->toBe(1)
        ->and($result['stdout'])->toBe(($this->spin)('Installing module [Account]...')."Invalid root composer.json: <base>/composer.json\n")
        ->and(File::get($this->composerPath))->toBe('{nope');
});

it('writes nothing to stderr on any of its failures', function () {
    expect(($this->drive)(['module' => 'Ghost'])['stderr'])->toBe('')
        ->and(($this->drive)(['module' => '1 not valid'])['stderr'])->toBe('');
});
