<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\InstallModuleAction;
use Studeo\Modular\Console\Actions\ResolveInstalledNamespaceAction;
use Studeo\Modular\Support\RootComposer;
use Studeo\Modular\Tests\Support\ModulesRootFixture;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->fixture = new ModulesRootFixture([
        'resolve:ResolveModuleAction', 'resolve:ResolveModuleAction', 'resolve:DiscoverModulesAction', 'resolve:RootComposer',
        'resolve:InstallModuleAction', 'resolve:RootComposer', 'resolve:ResolveInstalledNamespaceAction',
    ]);
    $this->fixture->fakeComposer();
    $this->rootPath = $this->fixture->rootPath;
    $this->composerCalls = $this->fixture->composerCalls;
    $this->driver = $this->fixture->driver([RootComposer::class, InstallModuleAction::class, ResolveInstalledNamespaceAction::class]);
    $this->writeRoot = $this->fixture->writeRoot(...);
    ($this->writeRoot)();
    $this->modules = $this->fixture->modules(...);
    $this->installedKeys = $this->fixture->installedKeys(...);
    $this->spin = $this->fixture->spin(...);
    $this->spinAfterPrompt = $this->fixture->spinAfterPrompt(...);
    $this->bulk = 'Installing module(s) and dumping autoloads...';
    $this->question = fn (string ...$options): string => "\n  Which module(s) would you like to install? [".implode(',', $options)."]\n"
        .implode('', array_map(fn (string $option, int $index): string => "  {$option} ... {$index}  \n", $options, array_keys($options)))."❯ \n";
    $this->outcome = $this->fixture->outcome(...);
});

afterEach(fn () => $this->fixture->tearDown());

it('asks one multiselect whose options and default are every pending module, and installs the answer', function () {
    ($this->modules)('Account', 'Billing');
    $stdout = ($this->question)('Account', 'Billing').($this->spinAfterPrompt)($this->bulk)."Module [Account] installed successfully.\nModule [Billing] installed successfully.\n";

    expect($this->driver->run('module:install', [], ['Account,Billing']))->toBe(($this->outcome)(0, $stdout, 16))
        ->and(($this->installedKeys)())->toContain('Studeo\\Account\\', 'Studeo\\Billing\\')
        ->and($this->composerCalls->getArrayCopy())->toBe(['dumpAutoloads']);
});

it('installs only the module that was answered', function () {
    ($this->modules)('Account', 'Billing');
    $stdout = ($this->question)('Account', 'Billing').($this->spinAfterPrompt)($this->bulk)."Module [Billing] installed successfully.\n";

    expect($this->driver->run('module:install', [], ['Billing']))->toBe(($this->outcome)(0, $stdout, 8))
        ->and(($this->installedKeys)())->toContain('Studeo\\Billing\\')->not->toContain('Studeo\\Account\\');
});

it('takes the default, every pending module, for an empty answer', function () {
    ($this->modules)('Account', 'Billing');
    $stdout = ($this->question)('Account', 'Billing').($this->spinAfterPrompt)($this->bulk)."Module [Account] installed successfully.\nModule [Billing] installed successfully.\n";

    expect($this->driver->run('module:install', [], ['']))->toBe(($this->outcome)(0, $stdout, 1))
        ->and(($this->installedKeys)())->toContain('Studeo\\Account\\', 'Studeo\\Billing\\');
});

it('offers the pending modules only, in discovery order', function () {
    ($this->modules)('Account', 'Billing', 'Cleaning');
    ($this->writeRoot)(['Studeo\\Account\\' => 'modules/Account/']);
    $stdout = ($this->question)('Billing', 'Cleaning').($this->spinAfterPrompt)($this->bulk)."Module [Cleaning] installed successfully.\n";

    expect($this->driver->run('module:install', [], ['Cleaning']))->toBe(($this->outcome)(0, $stdout, 9));
});

it('does not ask with --all and installs every pending module', function () {
    ($this->modules)('Account', 'Billing');
    $stdout = ($this->spin)($this->bulk)."Module [Account] installed successfully.\nModule [Billing] installed successfully.\n";

    expect($this->driver->run('module:install', ['--all' => true], ['Billing']))->toBe(($this->outcome)(0, $stdout, 0))
        ->and($this->composerCalls->getArrayCopy())->toBe(['dumpAutoloads']);
});

it('does not ask without a terminal and installs every pending module', function () {
    ($this->modules)('Account', 'Billing');
    $stdout = ($this->spin)($this->bulk)."Module [Account] installed successfully.\nModule [Billing] installed successfully.\n";

    expect($this->driver->run('module:install', [], [], false))->toBe(($this->outcome)(0, $stdout));
});

it('installs every pending module with --all and no terminal', function () {
    ($this->modules)('Account');
    $stdout = ($this->spin)($this->bulk)."Module [Account] installed successfully.\n";

    expect($this->driver->run('module:install', ['--all' => true], [], false))->toBe(($this->outcome)(0, $stdout));
});

it('says so, and asks nothing, when there are no modules', function () {
    $stdout = "No modules found in [<modules>].\n";

    expect($this->driver->run('module:install', [], ['Account']))->toBe(($this->outcome)(0, $stdout))
        ->and($this->composerCalls->getArrayCopy())->toBe([]);
});

it('says so, and asks nothing, when every module is installed', function () {
    ($this->modules)('Account');
    ($this->writeRoot)(['Studeo\\Account\\' => 'modules/Account/']);

    expect($this->driver->run('module:install', [], ['Account']))->toBe(($this->outcome)(0, "All modules are already installed.\n"))
        ->and($this->composerCalls->getArrayCopy())->toBe([]);
});

it('treats a module mapped by path only as installed', function () {
    ($this->modules)('Account');
    ($this->writeRoot)(['Other\\Account\\' => 'modules/Account/']);

    expect($this->driver->run('module:install', [], []))->toBe(($this->outcome)(0, "All modules are already installed.\n"));
});

it('lists the skipped directories first, in name order, before every other line', function () {
    ($this->modules)('Account');
    File::ensureDirectoryExists("{$this->rootPath}/Bare");
    File::ensureDirectoryExists("{$this->rootPath}/Legacy");
    File::put("{$this->rootPath}/Legacy/composer.json", '{}');
    $skipped = "Skipped [Bare]: no module.json.\n"
        ."Skipped [Legacy]: legacy composer.json found, no module.json. Run [php artisan module:install Legacy] to see the module.json to create.\n";

    expect($this->driver->run('module:install', [], ['Account']))->toBe(($this->outcome)(0, $skipped.($this->question)('Account').($this->spinAfterPrompt)($this->bulk)."Module [Account] installed successfully.\n", 8));
});

it('lists the skipped directories before the no-modules and all-installed lines', function () {
    File::ensureDirectoryExists("{$this->rootPath}/Bare");
    $skipped = "Skipped [Bare]: no module.json.\n";

    expect($this->driver->run('module:install', [], []))->toBe(($this->outcome)(0, $skipped."No modules found in [<modules>].\n"));

    ($this->modules)('Account');
    ($this->writeRoot)(['Studeo\\Account\\' => 'modules/Account/']);

    expect($this->driver->run('module:install', [], []))->toBe(($this->outcome)(0, $skipped."All modules are already installed.\n"));
});

it('installs a named module without asking, with its own spin message', function () {
    ($this->modules)('Account', 'Billing');
    $stdout = ($this->spin)('Installing module [Account]...')."Module [Account] installed successfully.\n";

    expect($this->driver->run('module:install', ['module' => 'Account'], ['Billing']))->toBe(($this->outcome)(0, $stdout))
        ->and(($this->installedKeys)())->toContain('Studeo\\Account\\')->not->toContain('Studeo\\Billing\\');
});

it('says a named module is already installed, and does not run Composer', function () {
    ($this->modules)('Account');
    ($this->writeRoot)(['Studeo\\Account\\' => 'modules/Account/']);
    $stdout = ($this->spin)('Installing module [Account]...')."Module [Account] is already installed.\n";

    expect($this->driver->run('module:install', ['module' => 'Account']))->toBe(($this->outcome)(0, $stdout))
        ->and($this->composerCalls->getArrayCopy())->toBe([]);
});

it('refuses a missing module, an empty name, 0 and a padded name without asking', function () {
    ($this->modules)('Account');
    $studly = 'must be one StudlyCase segment of letters and digits, for example Account or BlogPosts.';

    expect($this->driver->run('module:install', ['module' => 'Ghost'], ['x']))->toBe(($this->outcome)(1, ($this->spin)('Installing module [Ghost]...')."Module [Ghost] not found at [<modules>/Ghost].\n"))
        ->and($this->driver->run('module:install', ['module' => ''], ['x'])['stdout'])->toBe("The module name can not be blank\n")
        ->and($this->driver->run('module:install', ['module' => '0'], ['x'])['stdout'])->toBe("The module name [0] {$studly}\n")
        ->and($this->driver->run('module:install', ['module' => ' x '], ['x'])['stdout'])->toBe("The module name [ x ] {$studly}\n");
});
