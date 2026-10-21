<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\Tests\Support\ModulesRootFixture;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->fixture = new ModulesRootFixture(['resolve:ResolveModuleAction', 'resolve:CreateModuleAction'], usesComposer: false);
    $this->rootPath = $this->fixture->rootPath;
    $this->driver = $this->fixture->driver([CreateModuleAction::class]);
    $this->question = "\n  What is the name of the module?\n❯ ";
    $this->studly = ModulesRootFixture::STUDLY;
    $this->namespaceOf = fn (string $name): string => json_decode(File::get("{$this->rootPath}/{$name}/module.json"), true)['namespace'];
    $this->outcome = $this->fixture->outcome(...);
});

afterEach(fn () => $this->fixture->tearDown());

it('creates a module from the argument and prints one success line, nothing on stderr', function () {
    expect($this->driver->run('module:make', ['module' => 'Account']))->toBe(($this->outcome)(0, "Module [Account] created successfully.\n"))
        ->and("{$this->rootPath}/Account/module.json")->toBeFile()
        ->and("{$this->rootPath}/Account/AccountServiceProvider.php")->toBeFile();
});

it('never prompts for an empty argument', function () {
    expect($this->driver->run('module:make', ['module' => ''], ['Blog']))->toBe(($this->outcome)(1, "The module name can not be blank\n"))
        ->and(File::directories($this->rootPath))->toBe([]);
});

it('never prompts for 0, which is a given name that is not valid', function () {
    expect($this->driver->run('module:make', ['module' => '0'], ['Blog']))->toBe(($this->outcome)(1, "The module name [0] {$this->studly}\n"))
        ->and(File::directories($this->rootPath))->toBe([]);
});

it('never prompts for a padded or a nested name', function () {
    expect($this->driver->run('module:make', ['module' => ' x '], ['Blog']))->toBe(($this->outcome)(1, "The module name [ x ] {$this->studly}\n"))
        ->and($this->driver->run('module:make', ['module' => 'a/b'], ['Blog'])['stdout'])->toBe("The module name [a/b] must be one segment. To prefix a module, use --root-namespace=a with the name b.\n");
});

it('asks for the name when it is not given, and creates that module', function () {
    expect($this->driver->run('module:make', [], ['Blog']))->toBe(($this->outcome)(0, $this->question."\nModule [Blog] created successfully.\n", 5))
        ->and("{$this->rootPath}/Blog/module.json")->toBeFile();
});

it('answers Required. on stdout and exits 1 for an empty answer, after reading the one answer', function () {
    expect($this->driver->run('module:make', [], ['', 'Blog']))->toBe(($this->outcome)(1, $this->question."\n   ERROR  Required.  \n\n\n", 1))
        ->and(File::directories($this->rootPath))->toBe([]);
});

it('names the missing module argument without a terminal, and asks nothing', function () {
    expect($this->driver->run('module:make', [], ['Blog'], false))->toBe(($this->outcome)(1, "The [module] argument must be given when the command is not interactive, e.g. module:make Account.\n"));
});

it('validates a prompted name like a given one', function () {
    expect($this->driver->run('module:make', [], ['0']))->toBe(($this->outcome)(1, $this->question."\nThe module name [0] {$this->studly}\n", 2));
});

it('takes the namespace from the configuration without --root-namespace', function () {
    $this->driver->run('module:make', ['module' => 'Account']);

    expect(($this->namespaceOf)('Account'))->toBe('Studeo\\Account');
});

it('takes the namespace from --root-namespace, and no prefix from an empty one', function () {
    $this->driver->run('module:make', ['module' => 'Account', '--root-namespace' => 'Acme']);
    $this->driver->run('module:make', ['module' => 'Billing', '--root-namespace' => '']);

    expect(($this->namespaceOf)('Account'))->toBe('Acme\\Account')
        ->and(($this->namespaceOf)('Billing'))->toBe('Billing');
});

it('reads --root-namespace after the prompt, so a bad prefix is reported once the name is answered', function () {
    $result = $this->driver->run('module:make', ['--root-namespace' => 'App'], ['Blog']);

    expect($result['status'])->toBe(1)
        ->and($result['answered'])->toBe(5)
        ->and($result['stdout'])->toBe($this->question."\nThe root namespace [App] starts with [App], which belongs to the root application or a common vendor. Choose another module name, or a root namespace such as --root-namespace=Acme.\n")
        ->and(File::directories($this->rootPath))->toBe([]);
});

it('refuses a bad prefix that is given with a name, and writes nothing', function () {
    $result = $this->driver->run('module:make', ['module' => 'Account', '--root-namespace' => 'App\\']);

    expect($result['status'])->toBe(1)
        ->and($result['stderr'])->toBe('')
        ->and($result['answered'])->toBe(0)
        ->and($result['stdout'])->toBe("The root namespace [App\\] must not end with a backslash.\n")
        ->and(File::directories($this->rootPath))->toBe([]);
});

it('refuses an existing module and keeps it as it was', function () {
    $this->driver->run('module:make', ['module' => 'Account']);
    $before = File::get("{$this->rootPath}/Account/module.json");
    $result = $this->driver->run('module:make', ['module' => 'Account']);

    expect($result['status'])->toBe(1)
        ->and($result['stdout'])->toBe("Module [Account] already exists.\n")
        ->and(File::get("{$this->rootPath}/Account/module.json"))->toBe($before);
});
