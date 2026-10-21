<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->modulesPath = config('modular.root_path');
    File::deleteDirectory($this->modulesPath);
    File::ensureDirectoryExists($this->modulesPath);

    config(['modular.root_namespace' => 'Studeo']);
    $this->artisan('module:make', ['module' => 'Account'])->assertSuccessful();
});

afterEach(function () {
    File::deleteDirectory($this->modulesPath);
});

/**
 * Runs generators against the existing module and returns every generated PHP file's
 * namespace declarations.
 *
 * @return array<string, string>
 */
function generatedNamespaces(object $test): array
{
    fakeInertiaVueInstalled();

    foreach ([
        ['module:model', ['name' => 'Post']],
        ['module:action', ['name' => 'CreatePost']],
        ['module:controller', ['name' => 'ShowPost']],
        ['module:livewire', ['name' => 'Posts', '--classic' => true]],
        ['module:policy', ['name' => 'PostPolicy', '--model' => 'Post']],
        ['module:enum', ['name' => 'Status', '--cases' => 'draft,live']],
    ] as [$command, $options]) {
        $test->artisan($command, ['--module' => 'Account', ...$options])->assertSuccessful();
    }

    $namespaces = [];

    foreach (File::allFiles($test->modulesPath.'/Account') as $file) {
        if (str_ends_with($file->getFilename(), '.php') && preg_match('/^namespace (.+);$/m', File::get($file->getPathname()), $match)) {
            $namespaces[substr($file->getPathname(), strlen($test->modulesPath) + 1)] = $match[1];
        }
    }

    return $namespaces;
}

it('generates into the manifest namespace after the config prefix changes', function (?string $changedTo) {
    config(['modular.root_namespace' => $changedTo]);

    $namespaces = generatedNamespaces($this);

    expect($namespaces)->not->toBe([])
        ->and($namespaces['Account/Models/Post.php'])->toBe('Studeo\\Account\\Models')
        ->and($namespaces['Account/Actions/CreatePostAction.php'])->toBe('Studeo\\Account\\Actions')
        ->and($namespaces['Account/Controllers/ShowPostController.php'])->toBe('Studeo\\Account\\Controllers')
        ->and($namespaces['Account/Livewire/Posts.php'])->toBe('Studeo\\Account\\Livewire');

    foreach ($namespaces as $namespace) {
        expect($namespace)->toStartWith('Studeo\\Account');
    }

    foreach (File::allFiles($this->modulesPath.'/Account') as $file) {
        expect(File::get($file->getPathname()))->not->toContain('Acme\\');
    }
})->with([
    'another prefix' => ['Acme'],
    'no prefix' => [null],
]);

it('keeps a prefix-less module prefix-less after the config gains a prefix', function () {
    $this->artisan('module:make', ['module' => 'Billing', '--root-namespace' => ''])->assertSuccessful();
    config(['modular.root_namespace' => 'Acme']);

    $this->artisan('module:model', ['name' => 'Invoice', '--module' => 'Billing'])->assertSuccessful();

    expect(File::get($this->modulesPath.'/Billing/Models/Invoice.php'))->toContain("namespace Billing\\Models;\n");
});
