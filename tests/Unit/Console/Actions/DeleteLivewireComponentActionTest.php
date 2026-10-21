<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateLivewireComponentAction;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\Console\Actions\DeleteLivewireComponentAction;
use Studeo\Modular\DataObjects\LivewireComponentOptions;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Enums\LivewireComponentType;
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
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

test('it deletes a classic component and its view and test files', function () {
    $creator = app(CreateLivewireComponentAction::class);
    $creator->execute($this->blogModule, 'PostList');

    $classPath = $this->blogModule->path.'/Livewire/PostList.php';
    $viewPath = $this->blogModule->path.'/resources/views/livewire/post-list.blade.php';
    $testPath = $this->blogModule->path.'/tests/Feature/Livewire/PostListTest.php';

    expect($classPath)->toBeFile()
        ->and($viewPath)->toBeFile()
        ->and($testPath)->toBeFile();

    $deleter = app(DeleteLivewireComponentAction::class);
    $result = $deleter->execute($this->blogModule, 'PostList');

    expect($classPath)->not->toBeFile()
        ->and($viewPath)->not->toBeFile()
        ->and($testPath)->not->toBeFile()
        ->and($result->deletedFiles)->toContain($classPath)
        ->and($result->deletedFiles)->toContain($viewPath)
        ->and($result->deletedFiles)->toContain($testPath);
});

test('it deletes an MFC component directory and all its contents', function () {
    $creator = app(CreateLivewireComponentAction::class);
    $creator->execute($this->blogModule, 'PostList', new LivewireComponentOptions(
        type: LivewireComponentType::Mfc,
        assets: true,
    ));

    $dir = $this->blogModule->path.'/Livewire/⚡post-list';
    expect($dir)->toBeDirectory()
        ->and($dir.'/post-list.php')->toBeFile()
        ->and($dir.'/post-list.blade.php')->toBeFile()
        ->and($dir.'/post-list.test.php')->toBeFile()
        ->and($dir.'/post-list.css')->toBeFile()
        ->and($dir.'/post-list.js')->toBeFile();

    $deleter = app(DeleteLivewireComponentAction::class);
    $result = $deleter->execute($this->blogModule, 'PostList');

    expect($dir)->not->toBeDirectory()
        ->and($result->component->name)->toBe('post-list')
        ->and($result->component->type)->toBe('mfc');
});

test('it deletes an SFC component and its test file', function () {
    $creator = app(CreateLivewireComponentAction::class);
    $creator->execute($this->blogModule, 'QuickStatus', new LivewireComponentOptions(
        type: LivewireComponentType::Sfc,
    ));

    $sfcPath = $this->blogModule->path.'/Livewire/⚡quick-status.blade.php';
    $testPath = $this->blogModule->path.'/tests/Feature/Livewire/QuickStatusTest.php';

    expect($sfcPath)->toBeFile()
        ->and($testPath)->toBeFile();

    $deleter = app(DeleteLivewireComponentAction::class);
    $result = $deleter->execute($this->blogModule, 'QuickStatus');

    expect($sfcPath)->not->toBeFile()
        ->and($testPath)->not->toBeFile()
        ->and($result->deletedFiles)->toContain($sfcPath)
        ->and($result->deletedFiles)->toContain($testPath);
});

test('it prunes empty parent directories up to root livewire directories', function () {
    $creator = app(CreateLivewireComponentAction::class);
    $creator->execute($this->blogModule, 'Admin/Settings/Profile');

    $classDir = $this->blogModule->path.'/Livewire/Admin/Settings';
    $viewDir = $this->blogModule->path.'/resources/views/livewire/admin/settings';
    $testDir = $this->blogModule->path.'/tests/Feature/Livewire/Admin/Settings';

    expect($classDir)->toBeDirectory()
        ->and($viewDir)->toBeDirectory()
        ->and($testDir)->toBeDirectory();

    $deleter = app(DeleteLivewireComponentAction::class);
    $deleter->execute($this->blogModule, 'Admin/Settings/Profile');

    // Nested subdirectories should be pruned
    expect($this->blogModule->path.'/Livewire/Admin')->not->toBeDirectory()
        ->and($this->blogModule->path.'/resources/views/livewire/admin')->not->toBeDirectory()
        ->and($this->blogModule->path.'/tests/Feature/Livewire/Admin')->not->toBeDirectory()
        // Base directories must be preserved
        ->and($this->blogModule->path.'/Livewire')->toBeDirectory()
        ->and($this->blogModule->path.'/resources/views/livewire')->toBeDirectory()
        ->and($this->blogModule->path.'/tests/Feature/Livewire')->toBeDirectory();
});

test('it does not prune parent directory if it contains other files or components', function () {
    $creator = app(CreateLivewireComponentAction::class);
    $creator->execute($this->blogModule, 'Admin/UserTable');
    $creator->execute($this->blogModule, 'Admin/RoleTable');

    $deleter = app(DeleteLivewireComponentAction::class);
    $deleter->execute($this->blogModule, 'Admin/UserTable');

    expect($this->blogModule->path.'/Livewire/Admin')->toBeDirectory()
        ->and($this->blogModule->path.'/Livewire/Admin/RoleTable.php')->toBeFile()
        ->and($this->blogModule->path.'/Livewire/Admin/UserTable.php')->not->toBeFile();
});

test('it throws an exception when component does not exist in module', function () {
    $deleter = app(DeleteLivewireComponentAction::class);

    expect(fn () => $deleter->execute($this->blogModule, 'NonExistent'))
        ->toThrow(RuntimeException::class, 'Livewire component [NonExistent] does not exist in module [Blog].');
});

test('it throws an exception when module directory does not exist', function () {
    $ghostModule = new ModuleDefinition(
        name: 'Ghost',
        namespace: 'Studeo\\Ghost',
        path: $this->rootModulesPath.'/Ghost',
    );

    $deleter = app(DeleteLivewireComponentAction::class);

    expect(fn () => $deleter->execute($ghostModule, 'PostList'))
        ->toThrow(RuntimeException::class, "Module directory does not exist [{$ghostModule->path}].");
});
