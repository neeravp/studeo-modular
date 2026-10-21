<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->modulesPath = config('modular.root_path');
    File::deleteDirectory($this->modulesPath);
    File::ensureDirectoryExists($this->modulesPath);
});

afterEach(function () {
    File::deleteDirectory($this->modulesPath);
});

function manifestNamespace(object $test, string $module = 'Account'): string
{
    return json_decode(File::get($test->modulesPath."/{$module}/module.json"), true)['namespace'];
}

it('ships root_namespace null, with no namespace_strategy', function () {
    $shipped = require dirname(__DIR__, 2).'/config/modular.php';

    expect($shipped)->toHaveKey('root_namespace')
        ->and($shipped['root_namespace'])->toBeNull()
        ->and($shipped)->not->toHaveKey('namespace_strategy');
});

it('makes a module with no prefix under the shipped default config', function () {
    config(['modular.root_namespace' => (require dirname(__DIR__, 2).'/config/modular.php')['root_namespace']]);

    $this->artisan('module:make', ['module' => 'Account'])->assertSuccessful();

    expect(manifestNamespace($this))->toBe('Account')
        ->and(File::get($this->modulesPath.'/Account/AccountServiceProvider.php'))->toContain("namespace Account;\n");
});

it('prefixes the module with the configured root_namespace', function (string $configured, string $expected) {
    config(['modular.root_namespace' => $configured]);

    $this->artisan('module:make', ['module' => 'Account'])->assertSuccessful();

    expect(manifestNamespace($this))->toBe($expected);
})->with([
    'one segment' => ['Studeo', 'Studeo\\Account'],
    'several segments' => ['Acme\\Platform', 'Acme\\Platform\\Account'],
]);

it('lets --root-namespace beat the config for that module only', function () {
    config(['modular.root_namespace' => 'Studeo']);

    $this->artisan('module:make', ['module' => 'Account', '--root-namespace' => 'Acme'])->assertSuccessful();
    $this->artisan('module:make', ['module' => 'Billing'])->assertSuccessful();

    expect(manifestNamespace($this))->toBe('Acme\\Account')
        ->and(manifestNamespace($this, 'Billing'))->toBe('Studeo\\Billing')
        ->and(config('modular.root_namespace'))->toBe('Studeo');
});

it('lets an empty --root-namespace beat a configured prefix', function () {
    config(['modular.root_namespace' => 'Studeo']);

    $this->artisan('module:make', ['module' => 'Account', '--root-namespace' => ''])->assertSuccessful();

    expect(manifestNamespace($this))->toBe('Account');
});

it('accepts --root-namespace= typed on the command line', function () {
    config(['modular.root_namespace' => 'Studeo']);

    $this->artisan('module:make Account --root-namespace=')->assertSuccessful();

    expect(manifestNamespace($this))->toBe('Account');
});

it('gives the option no shortcut', function () {
    $option = collect($this->app->make('Illuminate\Contracts\Console\Kernel')->all()['module:make']->getDefinition()->getOptions())
        ->first(fn ($option) => $option->getName() === 'root-namespace');

    expect($option)->not->toBeNull()
        ->and($option->getShortcut())->toBeNull();
});

it('refuses the old prefixed module name and points at --root-namespace', function () {
    config(['modular.root_namespace' => 'Studeo']);

    $this->artisan('module:make', ['module' => 'Studeo\\Account'])
        ->expectsOutputToContain('--root-namespace=Studeo')
        ->assertFailed();

    expect(File::isDirectory($this->modulesPath.'/Account'))->toBeFalse()
        ->and(File::isDirectory($this->modulesPath.'/Studeo'))->toBeFalse();
});

it('refuses a module name that is not one studly segment', function (string $name) {
    $this->artisan('module:make', ['module' => $name])->assertFailed();

    expect(File::directories($this->modulesPath))->toBe([]);
})->with(['Studeo/Account', 'A\\B\\C', 'account', 'blog-posts']);

it('refuses a bad prefix, names the cause and writes nothing', function (string $prefix, string $message) {
    $this->artisan('module:make', ['module' => 'Account', '--root-namespace' => $prefix])
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect(File::directories($this->modulesPath))->toBe([]);
})->with([
    'leading backslash' => ['\\Acme', 'must not start with a backslash'],
    'trailing backslash' => ['Acme\\', 'must not end with a backslash'],
    'empty segment' => ['Acme\\\\Platform', 'empty segment'],
    'invalid segment' => ['Acme\\1st', '[1st]'],
    'reserved prefix' => ['Illuminate', '[Illuminate]'],
]);

it('refuses a configured prefix that is invalid, the same way', function () {
    config(['modular.root_namespace' => 'Tests\\Foo']);

    $this->artisan('module:make', ['module' => 'Account'])
        ->expectsOutputToContain('[Tests]')
        ->assertFailed();

    expect(File::directories($this->modulesPath))->toBe([]);
});

it('refuses a reserved module name with no prefix, and accepts it under a prefix', function () {
    config(['modular.root_namespace' => null]);

    $this->artisan('module:make', ['module' => 'Database'])
        ->expectsOutputToContain('[Database]')
        ->assertFailed();

    expect(File::isDirectory($this->modulesPath.'/Database'))->toBeFalse();

    $this->artisan('module:make', ['module' => 'Database', '--root-namespace' => 'Acme'])->assertSuccessful();

    expect(manifestNamespace($this, 'Database'))->toBe('Acme\\Database');
});
