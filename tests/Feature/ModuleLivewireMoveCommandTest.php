<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateLivewireComponentAction;
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

    $this->shopModule = new ModuleDefinition(
        name: 'Shop',
        namespace: 'Studeo\\Shop',
        path: $this->rootModulesPath.'/Shop',
    );

    app(CreateModuleAction::class)->execute($this->blogModule);
    app(CreateModuleAction::class)->execute($this->shopModule);
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

it('moves component across modules with --force option', function () {
    $creator = app(CreateLivewireComponentAction::class);
    $creator->execute($this->blogModule, 'PostList');

    $this->artisan('module:livewire:move', [
        'name' => 'PostList',
        'new-name' => 'ProductList',
        '--module' => 'Blog',
        '--to-module' => 'Shop',
        '--force' => true,
    ])
        ->expectsOutputToContain('Livewire component [PostList] moved to [ProductList] in module [Shop].')
        ->assertSuccessful();

    expect($this->blogModule->path.'/Livewire/PostList.php')->not->toBeFile()
        ->and($this->shopModule->path.'/Livewire/ProductList.php')->toBeFile()
        ->and($this->shopModule->path.'/resources/views/livewire/product-list.blade.php')->toBeFile()
        ->and($this->shopModule->path.'/tests/Feature/Livewire/ProductListTest.php')->toBeFile();
});

it('renames component within the same module with --force option', function () {
    $creator = app(CreateLivewireComponentAction::class);
    $creator->execute($this->blogModule, 'PostList');

    $this->artisan('module:livewire:move', [
        'name' => 'PostList',
        'new-name' => 'AllPosts',
        '--module' => 'Blog',
        '--force' => true,
    ])
        ->expectsOutputToContain('Livewire component [PostList] moved to [AllPosts] in module [Blog].')
        ->assertSuccessful();

    expect($this->blogModule->path.'/Livewire/PostList.php')->not->toBeFile()
        ->and($this->blogModule->path.'/Livewire/AllPosts.php')->toBeFile();
});

it('prompts to keep component in same module when --to-module is omitted', function () {
    $creator = app(CreateLivewireComponentAction::class);
    $creator->execute($this->blogModule, 'PostList');

    $this->artisan('module:livewire:move', [
        'name' => 'PostList',
        'new-name' => 'RecentPosts',
        '--module' => 'Blog',
    ])
        ->expectsConfirmation('Keep component in module [Blog]?', 'yes')
        ->expectsConfirmation('Are you sure you want to move [PostList] in [Blog] to [RecentPosts]?', 'yes')
        ->expectsOutputToContain('Livewire component [PostList] moved to [RecentPosts] in module [Blog].')
        ->assertSuccessful();

    expect($this->blogModule->path.'/Livewire/RecentPosts.php')->toBeFile();
});

it('prompts to select another module when user selects no for same module', function () {
    $creator = app(CreateLivewireComponentAction::class);
    $creator->execute($this->blogModule, 'PostList');

    $this->artisan('module:livewire:move', [
        'name' => 'PostList',
        'new-name' => 'ProductList',
        '--module' => 'Blog',
    ])
        ->expectsConfirmation('Keep component in module [Blog]?', 'no')
        ->expectsQuestion('Which module should the component be moved to?', 'Shop')
        ->expectsConfirmation('Are you sure you want to move [PostList] in [Blog] to [ProductList] in module [Shop]?', 'yes')
        ->expectsOutputToContain('Livewire component [PostList] moved to [ProductList] in module [Shop].')
        ->assertSuccessful();

    expect($this->shopModule->path.'/Livewire/ProductList.php')->toBeFile();
});

it('prompts for component name when name argument is omitted', function () {
    $creator = app(CreateLivewireComponentAction::class);
    $creator->execute($this->blogModule, 'PostList');

    $this->artisan('module:livewire:move', [
        'new-name' => 'AllPosts',
        '--module' => 'Blog',
        '--force' => true,
    ])
        ->expectsQuestion('Which Livewire component would you like to move from [Blog]?', 'PostList')
        ->expectsOutputToContain('Livewire component [PostList] moved to [AllPosts] in module [Blog].')
        ->assertSuccessful();

    expect($this->blogModule->path.'/Livewire/AllPosts.php')->toBeFile();
});

it('prompts for new name when new-name argument is omitted', function () {
    $creator = app(CreateLivewireComponentAction::class);
    $creator->execute($this->blogModule, 'PostList');

    $this->artisan('module:livewire:move', [
        'name' => 'PostList',
        '--module' => 'Blog',
        '--to-module' => 'Blog',
        '--force' => true,
    ])
        ->expectsQuestion('What should the new component name be?', 'CustomPosts')
        ->expectsOutputToContain('Livewire component [PostList] moved to [CustomPosts] in module [Blog].')
        ->assertSuccessful();

    expect($this->blogModule->path.'/Livewire/CustomPosts.php')->toBeFile();
});

it('cancels move when user declines confirmation', function () {
    $creator = app(CreateLivewireComponentAction::class);
    $creator->execute($this->blogModule, 'PostList');

    $this->artisan('module:livewire:move', [
        'name' => 'PostList',
        'new-name' => 'AllPosts',
        '--module' => 'Blog',
        '--to-module' => 'Blog',
    ])
        ->expectsConfirmation('Are you sure you want to move [PostList] in [Blog] to [AllPosts]?', 'no')
        ->expectsOutputToContain('Component [PostList] was not moved.')
        ->assertSuccessful();

    expect($this->blogModule->path.'/Livewire/PostList.php')->toBeFile()
        ->and($this->blogModule->path.'/Livewire/AllPosts.php')->not->toBeFile();
});

it('supports module:livewire:mv alias', function () {
    $creator = app(CreateLivewireComponentAction::class);
    $creator->execute($this->blogModule, 'PostList');

    $this->artisan('module:livewire:mv', [
        'name' => 'PostList',
        'new-name' => 'AllPosts',
        '--module' => 'Blog',
        '--force' => true,
    ])
        ->expectsOutputToContain('Livewire component [PostList] moved to [AllPosts] in module [Blog].')
        ->assertSuccessful();

    expect($this->blogModule->path.'/Livewire/AllPosts.php')->toBeFile();
});

it('fails when component does not exist', function () {
    $this->artisan('module:livewire:move', [
        'name' => 'Ghost',
        'new-name' => 'Target',
        '--module' => 'Blog',
        '--force' => true,
    ])
        ->expectsOutputToContain('Livewire component [Ghost] does not exist in module [Blog].')
        ->assertFailed();
});

it('fails when target component already exists', function () {
    $creator = app(CreateLivewireComponentAction::class);
    $creator->execute($this->blogModule, 'PostList');
    $creator->execute($this->blogModule, 'AllPosts');

    $this->artisan('module:livewire:move', [
        'name' => 'PostList',
        'new-name' => 'AllPosts',
        '--module' => 'Blog',
        '--force' => true,
    ])
        ->expectsOutputToContain('Livewire component [AllPosts] already exists in module [Blog].')
        ->assertFailed();
});
