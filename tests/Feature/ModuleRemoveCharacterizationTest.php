<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\RemoveModuleAction;
use Studeo\Modular\Console\Actions\ResolveInstalledNamespaceAction;
use Studeo\Modular\Support\RootComposer;
use Studeo\Modular\Tests\Helpers\OrderedFilesystem;
use Studeo\Modular\Tests\Support\ModulesRootFixture;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->fixture = new ModulesRootFixture([
        'resolve:ResolveModuleAction', 'resolve:RootComposer', 'resolve:ResolveInstalledNamespaceAction',
        'resolve:RemoveModuleAction', 'resolve:RootComposer', 'resolve:ResolveInstalledNamespaceAction',
    ]);
    $this->rootPath = $this->fixture->rootPath;
    $this->composerPath = $this->fixture->composerPath;
    $this->writeRoot = $this->fixture->writeRoot(...);
    ($this->writeRoot)();
    $this->fixture->modules('Account');
    $this->driver = $this->fixture->driver([RootComposer::class, ResolveInstalledNamespaceAction::class, RemoveModuleAction::class]);
    $this->warning = "\n\e[33m This will permanently delete the module [Account] and all its contents.\e[39m\n";
    $this->confirm = "\n  Are you sure you want to delete module [Account]? (yes/no) [no]\n❯ ";
    $this->outcome = $this->fixture->outcome(...);
    $this->studly = ModulesRootFixture::STUDLY;
});

afterEach(fn () => $this->fixture->tearDown());

it('warns first, then asks to confirm, and removes the module after a yes', function () {
    $stdout = $this->warning.$this->confirm."\nModule [Account] removed successfully.\n";

    expect($this->driver->run('module:remove', ['module' => 'Account'], ['yes']))->toBe(($this->outcome)(0, $stdout, 4))
        ->and("{$this->rootPath}/Account")->not->toBeDirectory();
});

it('keeps the module after a no', function () {
    $stdout = $this->warning.$this->confirm."\nModule [Account] was not removed.\n";

    expect($this->driver->run('module:remove', ['module' => 'Account'], ['no']))->toBe(($this->outcome)(0, $stdout, 3))
        ->and("{$this->rootPath}/Account/module.json")->toBeFile();
});

it('defaults the confirm to no, so an empty answer keeps the module', function () {
    $stdout = $this->warning.$this->confirm."\nModule [Account] was not removed.\n";

    expect($this->driver->run('module:remove', ['module' => 'Account'], ['']))->toBe(($this->outcome)(0, $stdout, 1))
        ->and("{$this->rootPath}/Account/module.json")->toBeFile();
});

it('neither warns nor asks with --force', function () {
    expect($this->driver->run('module:remove', ['module' => 'Account', '--force' => true], ['no']))->toBe(($this->outcome)(0, "Module [Account] removed successfully.\n"))
        ->and("{$this->rootPath}/Account")->not->toBeDirectory();
});

it('refuses without a terminal and without --force, names --force, and keeps the module', function () {
    $stdout = "The --force option must be given when the command is not interactive, e.g. module:remove Account --force.\n";

    expect($this->driver->run('module:remove', ['module' => 'Account'], ['yes'], false))->toBe(($this->outcome)(1, $stdout))
        ->and("{$this->rootPath}/Account/module.json")->toBeFile();
});

it('removes without a terminal when --force is given', function () {
    expect($this->driver->run('module:remove', ['module' => 'Account', '--force' => true], [], false))->toBe(($this->outcome)(0, "Module [Account] removed successfully.\n"))
        ->and("{$this->rootPath}/Account")->not->toBeDirectory();
});

it('refuses an installed module before any warning or question, with or without --force', function () {
    ($this->writeRoot)(['Studeo\\Account\\' => 'modules/Account/']);
    $before = File::get($this->composerPath);
    $error = "Cannot remove module [Account] because it is currently installed. Run [php artisan module:uninstall Account] first.\n";

    expect($this->driver->run('module:remove', ['module' => 'Account'], ['yes']))->toBe(($this->outcome)(1, $error))
        ->and($this->driver->run('module:remove', ['module' => 'Account', '--force' => true], ['yes']))->toBe(($this->outcome)(1, $error))
        ->and("{$this->rootPath}/Account/module.json")->toBeFile()
        ->and(File::get($this->composerPath))->toBe($before);
});

it('refuses a module whose directory is mapped under another namespace', function () {
    ($this->writeRoot)(['Other\\Account\\' => 'modules/Account/']);
    $error = "Cannot remove module [Account] because it is currently installed. Run [php artisan module:uninstall Account] first.\n";

    expect($this->driver->run('module:remove', ['module' => 'Account', '--force' => true]))->toBe(($this->outcome)(1, $error));
});

it('removes a module whose manifest is broken', function () {
    $this->writeRawModuleManifest("{$this->rootPath}/Account", '{not json');

    expect($this->driver->run('module:remove', ['module' => 'Account', '--force' => true]))->toBe(($this->outcome)(0, "Module [Account] removed successfully.\n"))
        ->and("{$this->rootPath}/Account")->not->toBeDirectory();
});

it('says a missing module is not found, with --force, and asks nothing', function () {
    expect($this->driver->run('module:remove', ['module' => 'Ghost', '--force' => true]))->toBe(($this->outcome)(1, "Module [Ghost] not found at [<modules>/Ghost].\n"));
});

it('refuses an empty name, 0 and a padded name, without asking', function () {
    expect($this->driver->run('module:remove', ['module' => ''], ['yes']))->toBe(($this->outcome)(1, "The module name can not be blank\n"))
        ->and($this->driver->run('module:remove', ['module' => '0'], ['yes'])['stdout'])->toBe("The module name [0] {$this->studly}\n")
        ->and($this->driver->run('module:remove', ['module' => ' Account'], ['yes'])['stdout'])->toBe("The module name [ Account] {$this->studly}\n");
});

it('names a missing or an invalid root composer.json before warning or asking', function () {
    File::delete($this->composerPath);
    $missing = $this->driver->run('module:remove', ['module' => 'Account'], ['yes']);
    File::put($this->composerPath, '{nope');
    $invalid = $this->driver->run('module:remove', ['module' => 'Account'], ['yes']);

    expect($missing)->toBe(($this->outcome)(1, "Root composer.json not found: <base>/composer.json\n"))
        ->and($invalid)->toBe(($this->outcome)(1, "Invalid root composer.json: <base>/composer.json\n"))
        ->and("{$this->rootPath}/Account/module.json")->toBeFile();
});

it('reads the manifest and composer.json twice before it deletes the directory, last', function () {
    $files = new OrderedFilesystem;
    $files->modulesRoot = $this->rootPath;
    app()->instance(Filesystem::class, $files);
    $json = '<package>/vendor/orchestra/testbench-core/laravel/composer.json';
    $this->driver->run('module:remove', ['module' => 'Account'], ['yes']);

    expect($files->calls)->toBe([
        'isFile <modules>/Account/module.json true',
        'isFile <modules>/Account/module.json true',
        'get <modules>/Account/module.json',
        'isFile <modules>/Account/module.json true',
        'isFile <modules>/Account/module.json true',
        'get <modules>/Account/module.json',
        "isFile {$json} true",
        "get {$json}",
        "isFile {$json} true",
        "get {$json}",
        'isFile <modules>/Account/module.json true',
        'isFile <modules>/Account/module.json true',
        'get <modules>/Account/module.json',
        "isFile {$json} true",
        "get {$json}",
        "isFile {$json} true",
        "get {$json}",
        'isDirectory <modules>/Account true',
        'deleteDirectory <modules>/Account',
    ]);
});
