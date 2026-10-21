<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\ResolveInstalledNamespaceAction;
use Studeo\Modular\Console\Actions\UninstallModuleAction;
use Studeo\Modular\Support\RootComposer;
use Studeo\Modular\Tests\Helpers\OrderedFilesystem;
use Studeo\Modular\Tests\Support\ModulesRootFixture;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->fixture = new ModulesRootFixture([
        'resolve:ResolveModuleAction', 'resolve:RootComposer', 'resolve:RootComposer',
        'resolve:ResolveInstalledNamespaceAction', 'resolve:UninstallModuleAction', 'resolve:RootComposer',
    ]);
    $this->rootPath = $this->fixture->rootPath;
    $this->composerPath = $this->fixture->composerPath;
    $this->writeRoot = $this->fixture->writeInstalled(...);
    ($this->writeRoot)();
    $this->rootJson = File::get($this->composerPath);
    $this->composerCalls = $this->fixture->composerCalls;
    $this->fixture->fakeComposer();
    $this->modules = $this->fixture->modules(...);
    $this->driver = $this->fixture->driver([RootComposer::class, ResolveInstalledNamespaceAction::class, UninstallModuleAction::class]);
    $this->question = fn (string ...$options): string => "\n  Which module would you like to uninstall?\n"
        .implode('', array_map(fn (string $option, int $index): string => "  {$option} ... {$index}  \n", $options, array_keys($options)))."❯ \n";
    $this->spin = $this->fixture->spin(...);
    $this->spinAfterPrompt = $this->fixture->spinAfterPrompt(...);
    $this->outcome = $this->fixture->outcome(...);
    $this->psr4 = $this->fixture->installedKeys(...);
    $this->studly = ModulesRootFixture::STUDLY;
});

afterEach(fn () => $this->fixture->tearDown());

it('offers the installed modules sorted and unique, and uninstalls the answer', function () {
    ($this->writeRoot)('Billing', 'Account', 'Cleaning');
    $stdout = ($this->question)('Account', 'Billing', 'Cleaning').($this->spinAfterPrompt)('Uninstalling module [Billing]...')."Module [Billing] uninstalled successfully.\n";

    expect($this->driver->run('module:uninstall', [], ['Billing']))->toBe(($this->outcome)(0, $stdout, 8))
        ->and(($this->psr4)())->toBe(['App\\', 'Studeo\\Account\\', 'Studeo\\Cleaning\\'])
        ->and($this->composerCalls->getArrayCopy())->toBe(['dumpAutoloads']);
});

it('lists a module once when several of its namespaces are installed', function () {
    File::put($this->composerPath, json_encode(['autoload' => ['psr-4' => [
        'Studeo\\Account\\' => 'modules/Account/',
        'Studeo\\Account\\Database\\Factories\\' => 'modules/Account/database/factories/',
        'Other\\' => 'elsewhere/',
    ]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    $stdout = ($this->question)('Account').($this->spinAfterPrompt)('Uninstalling module [Account]...')."Module [Account] uninstalled successfully.\n";

    expect($this->driver->run('module:uninstall', [], ['Account']))->toBe(($this->outcome)(0, $stdout, 8));
});

it('asks even when only one module is installed', function () {
    ($this->writeRoot)('Account');
    $stdout = ($this->question)('Account').($this->spinAfterPrompt)('Uninstalling module [Account]...')."Module [Account] uninstalled successfully.\n";

    expect($this->driver->run('module:uninstall', [], ['Account']))->toBe(($this->outcome)(0, $stdout, 8));
});

it('refuses an empty answer, and changes nothing', function () {
    ($this->writeRoot)('Billing', 'Account');
    $before = File::get($this->composerPath);
    $question = ($this->question)('Account', 'Billing');
    $stdout = $question."\n [ERROR] Value \"\" is invalid\n".substr($question, 0, -strlen("❯ \n")).'❯ Value "" is invalid'."\n";

    $result = $this->driver->run('module:uninstall', [], ['']);
    $result['stdout'] = preg_replace('/(\[ERROR\] Value "" is invalid) +\n/', "$1\n", $result['stdout']);

    expect($result)->toBe(($this->outcome)(1, $stdout, 1))
        ->and(File::get($this->composerPath))->toBe($before);
});

it('says nothing is installed and exits 0 before it reads any module', function () {
    $files = new OrderedFilesystem;
    $files->modulesRoot = $this->rootPath;
    app()->instance(Filesystem::class, $files);

    expect($this->driver->run('module:uninstall', [], ['Account']))->toBe(($this->outcome)(0, "No modules are currently installed.\n"))
        ->and($files->calls)->toBe(['isFile <package>/vendor/orchestra/testbench-core/laravel/composer.json true', 'get <package>/vendor/orchestra/testbench-core/laravel/composer.json'])
        ->and($this->composerCalls->getArrayCopy())->toBe([]);
});

it('names the missing module argument without a terminal, and changes nothing', function () {
    ($this->writeRoot)('Account');

    expect($this->driver->run('module:uninstall', [], ['Account'], false))->toBe(($this->outcome)(1, "The [module] argument must be given when the command is not interactive, e.g. module:uninstall Account.\n"))
        ->and(File::get($this->composerPath))->not->toBe($this->rootJson)
        ->and($this->composerCalls->getArrayCopy())->toBe([]);
});

it('says nothing is installed without a terminal, before the select', function () {
    expect($this->driver->run('module:uninstall', [], [], false))->toBe(($this->outcome)(0, "No modules are currently installed.\n"));
});

it('uninstalls a named module without asking and without listing the installed modules', function () {
    ($this->writeRoot)('Account', 'Billing');
    $files = new OrderedFilesystem;
    $files->modulesRoot = $this->rootPath;
    app()->instance(Filesystem::class, $files);
    $result = $this->driver->run('module:uninstall', ['module' => 'Account'], ['Billing']);

    expect($result)->toBe(($this->outcome)(0, ($this->spin)('Uninstalling module [Account]...')."Module [Account] uninstalled successfully.\n"))
        ->and(($this->psr4)())->toBe(['App\\', 'Studeo\\Billing\\'])
        ->and(array_slice($files->calls, 0, 2))->toBe(['isFile <modules>/Account/module.json false', 'isFile <modules>/Account/module.json false']);
});

it('says a named module is not installed and runs nothing', function () {
    ($this->writeRoot)('Billing');
    $stdout = ($this->spin)('Uninstalling module [Account]...')."Module [Account] is not installed.\n";

    expect($this->driver->run('module:uninstall', ['module' => 'Account']))->toBe(($this->outcome)(0, $stdout))
        ->and($this->composerCalls->getArrayCopy())->toBe([]);
});

it('uninstalls a module whose directory is gone, and one whose manifest is broken', function () {
    ($this->writeRoot)('Account', 'Billing');
    $this->writeRawModuleManifest("{$this->rootPath}/Billing", '{not json');
    $ok = fn (string $name): string => ($this->spin)("Uninstalling module [{$name}]...")."Module [{$name}] uninstalled successfully.\n";

    expect($this->driver->run('module:uninstall', ['module' => 'Account'])['stdout'])->toBe($ok('Account'))
        ->and($this->driver->run('module:uninstall', ['module' => 'Billing'])['stdout'])->toBe($ok('Billing'))
        ->and(($this->psr4)())->toBe(['App\\']);
});

it('reports a Composer exit code and restores composer.json', function () {
    ($this->writeRoot)('Account');
    $before = File::get($this->composerPath);
    $this->fixture->exit = 1;
    $result = $this->driver->run('module:uninstall', ['module' => 'Account']);

    expect($result)->toBe(($this->outcome)(1, ($this->spin)('Uninstalling module [Account]...')."Composer dump-autoload failed with exit code [1].\n"))
        ->and(File::get($this->composerPath))->toBe($before);
});

it('reports a throwing Composer and restores composer.json', function () {
    ($this->writeRoot)('Account');
    $before = File::get($this->composerPath);
    $this->fixture->throws = true;
    $result = $this->driver->run('module:uninstall', ['module' => 'Account']);

    expect($result)->toBe(($this->outcome)(1, ($this->spin)('Uninstalling module [Account]...')."composer is gone\n"))
        ->and(File::get($this->composerPath))->toBe($before);
});

it('refuses an empty name, 0 and a padded name before it spins', function () {
    expect($this->driver->run('module:uninstall', ['module' => '']))->toBe(($this->outcome)(1, "The module name can not be blank\n"))
        ->and($this->driver->run('module:uninstall', ['module' => '0'])['stdout'])->toBe("The module name [0] {$this->studly}\n")
        ->and($this->driver->run('module:uninstall', ['module' => ' x '])['stdout'])->toBe("The module name [ x ] {$this->studly}\n");
});

it('names a missing or an invalid root composer.json, with and without an argument', function () {
    File::delete($this->composerPath);
    $missing = "Root composer.json not found: <base>/composer.json\n";
    $withoutArgument = $this->driver->run('module:uninstall', []);
    $named = $this->driver->run('module:uninstall', ['module' => 'Account']);
    File::put($this->composerPath, '{nope');

    expect($withoutArgument)->toBe(($this->outcome)(1, $missing))
        ->and($named)->toBe(($this->outcome)(1, ($this->spin)('Uninstalling module [Account]...').$missing))
        ->and($this->driver->run('module:uninstall', [])['stdout'])->toBe("Invalid root composer.json: <base>/composer.json\n");
});
