<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Tests\Support\ModulesRootFixture;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->fixture = new ModulesRootFixture(usesComposer: false);
    $this->rootPath = $this->fixture->rootPath;
    $this->driver = $this->fixture->driver();
    $this->input = ['name' => 'Status', '--cases' => 'a,b'];
    $this->picked = ['resolve:ResolveModuleAction', 'resolve:DiscoverModulesAction'];
    $this->hint = "No modules found. Create one with [php artisan module:make Blog].\n";
    $this->list = "\n  Which module should this belong to?\n  Blog ... 0  \n  Shop ... 1  \n❯ \n";

    $this->modules = function (string ...$names) {
        foreach ($names as $name) {
            $this->writeModuleManifest("{$this->rootPath}/{$name}", $name, "Studeo\\{$name}");
        }
    };
    $this->skipped = function () {
        File::ensureDirectoryExists("{$this->rootPath}/Legacy");
        File::put("{$this->rootPath}/Legacy/composer.json", '{}');
        File::ensureDirectoryExists("{$this->rootPath}/Bare");
        $this->writeRawModuleManifest("{$this->rootPath}/Broken", '{not json');
        $this->writeRawModuleManifest("{$this->rootPath}/Renamed", '{"name":"Other","namespace":"Studeo\\\\Renamed"}');
        $this->writeRawModuleManifest("{$this->rootPath}/Bad Name", '{"name":"Bad Name","namespace":"Studeo\\\\Bad"}');
    };
    $this->skippedLines = "Skipped [Bad Name]: invalid module name: The module name [Bad Name] must be one StudlyCase segment of letters and digits, for example Account or BlogPosts.\n"
        ."Skipped [Bare]: no module.json.\n"
        ."Skipped [Broken]: invalid module.json: Invalid module manifest <modules>/Broken/module.json: Syntax error\n"
        ."Skipped [Legacy]: legacy composer.json found, no module.json. Run [php artisan module:install Legacy] to see the module.json to create.\n"
        ."Skipped [Renamed]: invalid module.json: Invalid module manifest <modules>/Renamed/module.json: name must be the directory name [Renamed].\n";
    $this->outcome = fn (int $status, string $stdout, array $resolved, int $answered = 0, string $stderr = ''): array => [
        'status' => $status,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'answered' => $answered,
        'resolved' => $resolved,
    ];
});

afterEach(fn () => $this->fixture->tearDown());

it('fails with the module:make hint and nothing else when there are no modules', function () {
    expect($this->driver->run('module:enum', $this->input))->toBe(($this->outcome)(1, $this->hint, $this->picked));
});

it('reads a missing modules root as no modules', function () {
    File::deleteDirectory($this->rootPath);

    expect($this->driver->run('module:enum', $this->input))->toBe(($this->outcome)(1, $this->hint, $this->picked));
});

it('lists every skipped directory after the hint, in name order, when there are no modules', function () {
    ($this->skipped)();

    expect($this->driver->run('module:enum', $this->input))->toBe(($this->outcome)(1, $this->hint.$this->skippedLines, $this->picked));
});

it('uses the only module and says so before the success lines', function () {
    ($this->modules)('Blog');
    $ok = "Using module [Blog].\nEnum [Status] created successfully in module [Blog].\nPath: <modules>/Blog/Enums/Status.php\n";

    expect($this->driver->run('module:enum', $this->input))->toBe(($this->outcome)(0, $ok, $this->picked))
        ->and("{$this->rootPath}/Blog/Enums/Status.php")->toBeFile();
});

it('uses the only module when skipped directories are beside it', function () {
    ($this->modules)('Blog');
    ($this->skipped)();
    $ok = "Using module [Blog].\nEnum [Status] created successfully in module [Blog].\nPath: <modules>/Blog/Enums/Status.php\n";

    expect($this->driver->run('module:enum', $this->input))->toBe(($this->outcome)(0, $ok, $this->picked));
});

it('uses the only module without a terminal', function () {
    ($this->modules)('Blog');
    $ok = "Using module [Blog].\nEnum [Status] created successfully in module [Blog].\nPath: <modules>/Blog/Enums/Status.php\n";

    expect($this->driver->run('module:enum', $this->input, [], false))->toBe(($this->outcome)(0, $ok, $this->picked));
});

it('asks with a select, and writes in the module of the first answer', function () {
    ($this->modules)('Shop', 'Blog');
    $ok = $this->list."Enum [Status] created successfully in module [Blog].\nPath: <modules>/Blog/Enums/Status.php\n";

    expect($this->driver->run('module:enum', $this->input, ['Blog']))->toBe(($this->outcome)(0, $ok, $this->picked, 5));
});

it('writes in the module of the second answer', function () {
    ($this->modules)('Shop', 'Blog');
    $ok = $this->list."Enum [Status] created successfully in module [Shop].\nPath: <modules>/Shop/Enums/Status.php\n";

    expect($this->driver->run('module:enum', $this->input, ['Shop']))->toBe(($this->outcome)(0, $ok, $this->picked, 5))
        ->and("{$this->rootPath}/Blog/Enums")->not->toBeDirectory();
});

it('offers only modules with a usable manifest', function () {
    ($this->modules)('Blog', 'Shop');
    ($this->skipped)();
    $ok = $this->list."Enum [Status] created successfully in module [Blog].\nPath: <modules>/Blog/Enums/Status.php\n";

    expect($this->driver->run('module:enum', $this->input, ['Blog']))->toBe(($this->outcome)(0, $ok, $this->picked, 5));
});

it('names --module and writes nothing when there are several modules and no terminal', function () {
    ($this->modules)('Blog', 'Shop');
    $error = "There is more than one module (Blog, Shop). Pass --module=<name> to choose one.\n";

    expect($this->driver->run('module:enum', $this->input, [], false))->toBe(($this->outcome)(1, $error, $this->picked))
        ->and("{$this->rootPath}/Blog/Enums")->not->toBeDirectory();
});

it('lets --module win over several modules without discovering any', function () {
    ($this->modules)('Blog', 'Shop');
    $ok = "Enum [Status] created successfully in module [Shop].\nPath: <modules>/Shop/Enums/Status.php\n";

    expect($this->driver->run('module:enum', $this->input + ['--module' => 'Shop']))->toBe(($this->outcome)(0, $ok, ['resolve:ResolveModuleAction']));
});

it('refuses an empty --module before any action is resolved', function () {
    ($this->modules)('Blog');

    expect($this->driver->run('module:enum', $this->input + ['--module' => '']))->toBe(($this->outcome)(1, "The module name is required.\n", []));
});

it('reports the resolver error for a --module that does not exist', function () {
    ($this->modules)('Blog');
    $error = "Module directory does not exist [<modules>/Ghost].\n";

    expect($this->driver->run('module:enum', $this->input + ['--module' => 'Ghost']))->toBe(($this->outcome)(1, $error, ['resolve:ResolveModuleAction']));
});

it('reports the manifest error for a --module with a broken manifest', function () {
    ($this->modules)('Blog');
    ($this->skipped)();
    $error = "Invalid module manifest <modules>/Broken/module.json: Syntax error\n";

    expect($this->driver->run('module:enum', $this->input + ['--module' => 'Broken']))->toBe(($this->outcome)(1, $error, ['resolve:ResolveModuleAction']))
        ->and("{$this->rootPath}/Broken/Enums")->not->toBeDirectory();
});

it('reports a manifest whose name is not the directory name when it is given by --module', function () {
    ($this->modules)('Blog');
    ($this->skipped)();
    $error = "Invalid module manifest <modules>/Renamed/module.json: name must be the directory name [Renamed].\n";

    expect($this->driver->run('module:enum', $this->input + ['--module' => 'Renamed']))->toBe(($this->outcome)(1, $error, ['resolve:ResolveModuleAction']));
});

it('accepts a directory without module.json when it is given by --module', function () {
    ($this->modules)('Blog');
    ($this->skipped)();
    $ok = "Enum [Status] created successfully in module [Legacy].\nPath: <modules>/Legacy/Enums/Status.php\n";

    expect($this->driver->run('module:enum', $this->input + ['--module' => 'Legacy']))->toBe(($this->outcome)(0, $ok, ['resolve:ResolveModuleAction']));
});
