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

    app(CreateModuleAction::class)->execute($this->blogModule);
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

it('deletes component without confirmation when --force is passed', function () {
    $creator = app(CreateLivewireComponentAction::class);
    $creator->execute($this->blogModule, 'PostList');

    $this->artisan('module:livewire:delete', [
        'name' => 'PostList',
        '--module' => 'Blog',
        '--force' => true,
    ])
        ->expectsOutputToContain('Livewire component [PostList] deleted successfully from module [Blog].')
        ->assertSuccessful();

    expect($this->blogModule->path.'/Livewire/PostList.php')->not->toBeFile();
});

it('asks for confirmation when --force is omitted and cancels on no', function () {
    $creator = app(CreateLivewireComponentAction::class);
    $creator->execute($this->blogModule, 'PostList');

    $this->artisan('module:livewire:delete', [
        'name' => 'PostList',
        '--module' => 'Blog',
    ])
        ->expectsConfirmation('Are you sure you want to delete component [PostList]?', 'no')
        ->expectsOutputToContain('Component [PostList] was not deleted.')
        ->assertSuccessful();

    expect($this->blogModule->path.'/Livewire/PostList.php')->toBeFile();
});

it('asks for confirmation when --force is omitted and deletes on yes', function () {
    $creator = app(CreateLivewireComponentAction::class);
    $creator->execute($this->blogModule, 'PostList');

    $this->artisan('module:livewire:delete', [
        'name' => 'PostList',
        '--module' => 'Blog',
    ])
        ->expectsConfirmation('Are you sure you want to delete component [PostList]?', 'yes')
        ->expectsOutputToContain('Livewire component [PostList] deleted successfully from module [Blog].')
        ->assertSuccessful();

    expect($this->blogModule->path.'/Livewire/PostList.php')->not->toBeFile();
});

it('prompts with a list of components when name is omitted', function () {
    $creator = app(CreateLivewireComponentAction::class);
    $creator->execute($this->blogModule, 'PostList');
    $creator->execute($this->blogModule, 'UserBadge');

    $this->artisan('module:livewire:delete', [
        '--module' => 'Blog',
        '--force' => true,
    ])
        ->expectsQuestion('Which Livewire component would you like to delete from [Blog]?', 'UserBadge')
        ->expectsOutputToContain('Livewire component [UserBadge] deleted successfully from module [Blog].')
        ->assertSuccessful();

    expect($this->blogModule->path.'/Livewire/UserBadge.php')->not->toBeFile()
        ->and($this->blogModule->path.'/Livewire/PostList.php')->toBeFile();
});

it('warns when no components exist in the module and name is omitted', function () {
    $this->artisan('module:livewire:delete', [
        '--module' => 'Blog',
    ])
        ->expectsOutputToContain('No Livewire components found in module [Blog].')
        ->assertSuccessful();
});

it('fails when component does not exist', function () {
    $this->artisan('module:livewire:delete', [
        'name' => 'NonExistent',
        '--module' => 'Blog',
        '--force' => true,
    ])
        ->expectsOutputToContain('Livewire component [NonExistent] does not exist in module [Blog].')
        ->assertFailed();
});

it('supports module:livewire:rm alias', function () {
    $creator = app(CreateLivewireComponentAction::class);
    $creator->execute($this->blogModule, 'PostList');

    $this->artisan('module:livewire:rm', [
        'name' => 'PostList',
        '--module' => 'Blog',
        '--force' => true,
    ])
        ->expectsOutputToContain('Livewire component [PostList] deleted successfully from module [Blog].')
        ->assertSuccessful();

    expect($this->blogModule->path.'/Livewire/PostList.php')->not->toBeFile();
});
