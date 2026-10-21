<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Tests\Support\ModulesRootFixture;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->fixture = new ModulesRootFixture(usesComposer: false);
    $this->rootPath = $this->fixture->rootPath;
    $this->driver = $this->fixture->driver();
    $this->hint = "No modules found. Create one with [php artisan module:make Blog].\n";
    $this->several = "There is more than one module (Blog, Shop). Pass --module=<name> to choose one.\n";
    $this->list = "\n  Which module should this belong to?\n  Blog ... 0  \n  Shop ... 1  \n❯ \n";
    $this->pair = ['resolve:ResolveModuleAction', 'resolve:DiscoverModulesAction'];
    $this->modules = function (string ...$names) {
        foreach ($names as $name) {
            $this->writeModuleManifest("{$this->rootPath}/{$name}", $name, "Studeo\\{$name}");
        }
    };
    $this->outcome = fn (int $status, string $stdout, string $stderr, array $resolved, int $answered = 0): array => [
        'status' => $status,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'answered' => $answered,
        'resolved' => $resolved,
    ];
});

afterEach(fn () => $this->fixture->tearDown());

it('prints the announcement and the summary on stdout in text format', function () {
    ($this->modules)('Blog');
    $resolved = [...$this->pair, ...$this->pair, ...$this->pair];

    expect($this->driver->run('module:check', []))->toBe(($this->outcome)(0, "Using module [Blog].\n0 errors, 0 warnings.\n", '', $resolved));
});

it('prints the announcement on stderr and only the findings on stdout in json format', function () {
    ($this->modules)('Blog');
    $resolved = [...$this->pair, ...$this->pair, ...$this->pair];

    expect($this->driver->run('module:check', ['--format' => 'json']))->toBe(($this->outcome)(0, "[]\n", "Using module [Blog].\n", $resolved));
});

it('draws the select on stderr and keeps stdout to the findings array in json format when there are several modules and a terminal', function () {
    ($this->modules)('Blog', 'Shop');
    $resolved = [...$this->pair, ...$this->pair, ...$this->pair];

    $result = $this->driver->run('module:check', ['--format' => 'json'], ['Shop']);

    expect($result)->toBe(($this->outcome)(0, "[]\n", $this->list, $resolved, 5))
        ->and(json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR))->toBe([]);
});

it('puts the select transcript before the summary on stdout in text format', function () {
    ($this->modules)('Blog', 'Shop');
    $resolved = [...$this->pair, ...$this->pair, ...$this->pair];

    expect($this->driver->run('module:check', [], ['Blog']))->toBe(($this->outcome)(0, $this->list."0 errors, 0 warnings.\n", '', $resolved, 5));
});

it('exits 2 and names --module on stderr when there are several modules and no terminal', function (array $options) {
    ($this->modules)('Blog', 'Shop');
    $resolved = [...$this->pair, ...$this->pair, ...$this->pair];

    expect($this->driver->run('module:check', $options, [], false))->toBe(($this->outcome)(2, '', $this->several, $resolved));
})->with([[[]], [['--format' => 'json']]]);

it('exits 2 with the module:make hint on stderr for --all with no modules', function () {
    $resolved = [...$this->pair, ...$this->pair];

    expect($this->driver->run('module:check', ['--all' => true]))->toBe(($this->outcome)(2, '', $this->hint, $resolved));
});

it('lists the skipped directories after the hint for --all with no modules', function () {
    File::ensureDirectoryExists("{$this->rootPath}/Legacy");
    File::put("{$this->rootPath}/Legacy/composer.json", '{}');
    File::ensureDirectoryExists("{$this->rootPath}/Bare");
    $skipped = "Skipped [Bare]: no module.json.\n"
        ."Skipped [Legacy]: legacy composer.json found, no module.json. Run [php artisan module:install Legacy] to see the module.json to create.\n";
    $resolved = [...$this->pair, ...$this->pair];

    expect($this->driver->run('module:check', ['--all' => true]))->toBe(($this->outcome)(2, '', $this->hint.$skipped, $resolved));
});

it('refuses --module together with --all before it resolves any module', function () {
    ($this->modules)('Blog');
    $resolved = [...$this->pair, ...$this->pair];

    expect($this->driver->run('module:check', ['--all' => true, '--module' => 'Blog']))->toBe(($this->outcome)(2, '', "Pass either --module or --all, not both.\n", $resolved));
});

it('lets --module win and prints no announcement', function () {
    ($this->modules)('Blog', 'Shop');
    $resolved = [...$this->pair, ...$this->pair, 'resolve:ResolveModuleAction'];

    expect($this->driver->run('module:check', ['--module' => 'Shop']))->toBe(($this->outcome)(0, "0 errors, 0 warnings.\n", '', $resolved));
});

it('reports a resolved module that has no module.json file', function () {
    ($this->modules)('Blog');
    File::delete("{$this->rootPath}/Blog/module.json");
    $resolved = [...$this->pair, ...$this->pair, 'resolve:ResolveModuleAction'];

    expect($this->driver->run('module:check', ['--module' => 'Blog']))
        ->toBe(($this->outcome)(2, '', "Module [Blog] has no module.json in <modules>/Blog.\n", $resolved));
});

it('reports a broken manifest given by --module on stderr and prints nothing on stdout in json', function () {
    ($this->modules)('Blog');
    File::put("{$this->rootPath}/Blog/module.json", '{not json');
    $resolved = [...$this->pair, ...$this->pair, 'resolve:ResolveModuleAction'];
    $error = "Invalid module manifest <modules>/Blog/module.json: Syntax error\n";

    expect($this->driver->run('module:check', ['--module' => 'Blog', '--format' => 'json']))->toBe(($this->outcome)(2, '', $error, $resolved));
});
