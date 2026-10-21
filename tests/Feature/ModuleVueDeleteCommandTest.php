<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\Console\Actions\CreateVueComponentAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\VueComponentOptions;
use Studeo\Modular\Enums\VueComponentType;
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

it('deletes Vue component without confirmation when --force is passed', function () {
    $creator = app(CreateVueComponentAction::class);
    $creator->execute($this->blogModule, 'PostCard');

    $this->artisan('module:vue:delete', [
        'name' => 'PostCard',
        '--module' => 'Blog',
        '--force' => true,
    ])
        ->expectsOutputToContain('Vue Component [PostCard] deleted successfully from module [Blog].')
        ->assertSuccessful();

    expect($this->blogModule->path.'/resources/js/Components/PostCard.vue')->not->toBeFile();
});

it('asks for confirmation when --force is omitted and cancels on no', function () {
    $creator = app(CreateVueComponentAction::class);
    $creator->execute($this->blogModule, 'PostCard');

    $this->artisan('module:vue:delete', [
        'name' => 'PostCard',
        '--module' => 'Blog',
    ])
        ->expectsConfirmation('Are you sure you want to delete component [PostCard]?', 'no')
        ->expectsOutputToContain('Component [PostCard] was not deleted.')
        ->assertSuccessful();

    expect($this->blogModule->path.'/resources/js/Components/PostCard.vue')->toBeFile();
});

it('asks for confirmation when --force is omitted and deletes on yes', function () {
    $creator = app(CreateVueComponentAction::class);
    $creator->execute($this->blogModule, 'PostCard');

    $this->artisan('module:vue:delete', [
        'name' => 'PostCard',
        '--module' => 'Blog',
    ])
        ->expectsConfirmation('Are you sure you want to delete component [PostCard]?', 'yes')
        ->expectsOutputToContain('Vue Component [PostCard] deleted successfully from module [Blog].')
        ->assertSuccessful();

    expect($this->blogModule->path.'/resources/js/Components/PostCard.vue')->not->toBeFile();
});

it('prompts with a list of components when name is omitted', function () {
    $creator = app(CreateVueComponentAction::class);
    $creator->execute($this->blogModule, 'PostCard');
    $creator->execute($this->blogModule, 'Posts/Index', new VueComponentOptions(type: VueComponentType::Page));

    $this->artisan('module:vue:delete', [
        '--module' => 'Blog',
        '--force' => true,
    ])
        ->expectsQuestion('Which Vue component would you like to delete from [Blog]?', 'Components/PostCard')
        ->expectsOutputToContain('Vue Component [PostCard] deleted successfully from module [Blog].')
        ->assertSuccessful();

    expect($this->blogModule->path.'/resources/js/Components/PostCard.vue')->not->toBeFile()
        ->and($this->blogModule->path.'/resources/js/Pages/Posts/Index.vue')->toBeFile();
});

it('warns when no components exist in the module and name is omitted', function () {
    $this->artisan('module:vue:delete', [
        '--module' => 'Blog',
    ])
        ->expectsOutputToContain('No Vue components found in module [Blog].')
        ->assertSuccessful();
});

it('fails when component does not exist', function () {
    $this->artisan('module:vue:delete', [
        'name' => 'NonExistent',
        '--module' => 'Blog',
        '--force' => true,
    ])
        ->expectsOutputToContain('Vue component [NonExistent] does not exist in module [Blog].')
        ->assertFailed();
});

it('supports module:page:delete and module:vue:rm aliases', function () {
    $creator = app(CreateVueComponentAction::class);
    $creator->execute($this->blogModule, 'Posts/Index', new VueComponentOptions(type: VueComponentType::Page));

    $this->artisan('module:page:delete', [
        'name' => 'Posts/Index',
        '--module' => 'Blog',
        '--force' => true,
    ])
        ->expectsOutputToContain('Vue Page [Posts/Index] deleted successfully from module [Blog].')
        ->assertSuccessful();

    expect($this->blogModule->path.'/resources/js/Pages/Posts/Index.vue')->not->toBeFile();
});
