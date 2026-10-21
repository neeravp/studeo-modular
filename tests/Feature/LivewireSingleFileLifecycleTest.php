<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->rootModulesPath = config('modular.root_path');
    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);

    $this->blogModule = new ModuleDefinition(
        name: 'Blog',
        namespace: 'Studeo\\Blog',
        path: $this->rootModulesPath.'/Blog',
    );

    app(CreateModuleAction::class)->execute($this->blogModule);

    $this->entries = fn (string $directory): array => array_values(array_diff(scandir($this->blogModule->path.'/'.$directory), ['.', '..']));
    $this->moduleFiles = function (string $directory = ''): array {
        $found = [];

        foreach (array_diff(scandir($this->blogModule->path.'/'.$directory), ['.', '..']) as $entry) {
            $relative = ltrim($directory.'/'.$entry, '/');
            $found = [...$found, ...(is_dir($this->blogModule->path.'/'.$relative) ? ($this->moduleFiles)($relative) : [$relative])];
        }

        return $found;
    };
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

it('writes the test file of a single-file component under its studly name and removes it with the component', function () {
    $before = ($this->moduleFiles)();

    $this->artisan('module:livewire', ['name' => 'Posts/create', '--module' => 'Blog', '--sfc' => true])->assertSuccessful();
    $this->artisan('module:livewire', ['name' => 'post-list', '--module' => 'Blog', '--sfc' => true])->assertSuccessful();

    expect(($this->entries)('tests/Feature/Livewire'))->toEqualCanonicalizing(['PostListTest.php', 'Posts'])
        ->and(($this->entries)('tests/Feature/Livewire/Posts'))->toBe(['CreateTest.php'])
        ->and(($this->entries)('Livewire'))->toEqualCanonicalizing(['Posts', '⚡post-list.blade.php'])
        ->and(($this->entries)('Livewire/Posts'))->toBe(['⚡create.blade.php']);

    $this->artisan('module:livewire:delete', ['name' => 'Posts/create', '--module' => 'Blog', '--force' => true])->assertSuccessful();
    $this->artisan('module:livewire:delete', ['name' => 'post-list', '--module' => 'Blog', '--force' => true])->assertSuccessful();

    expect(($this->moduleFiles)())->toBe($before);
});
