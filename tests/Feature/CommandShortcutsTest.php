<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

uses(TestCase::class);

beforeEach(function () {
    $this->rootModulesPath = config('modular.root_path');
    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);

    $this->blogModule = new ModuleDefinition('Blog', 'Studeo\\Blog', $this->rootModulesPath.'/Blog');
    app(CreateModuleAction::class)->execute($this->blogModule);
    fakeInertiaVueInstalled();
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

it('declares exactly the documented shortcuts, and each one means the same everywhere', function () {
    $shortcuts = collect(Artisan::all())
        ->filter(fn (Command $command) => str_starts_with($command->getName(), 'module:'))
        ->mapWithKeys(fn (Command $command) => [$command->getName() => collect($command->getDefinition()->getOptions())
            ->mapWithKeys(fn ($option) => [$option->getName() => $option->getShortcut()])->filter()->all()])
        ->filter()->all();

    expect($shortcuts)->toBe([
        'module:controller' => ['resource' => 'r'],
        'module:vue' => ['page' => 'p', 'form' => 'f'],
        'module:vue:delete' => ['page' => 'p'],
        'module:vue:move' => ['page' => 'p'],
    ]);
});

it('creates a page with -p and refuses a form without a schema with -f', function () {
    $this->artisan('module:vue', ['name' => 'Posts/Index', '--module' => 'Blog', '-p' => true])->assertSuccessful();
    $this->artisan('module:vue', ['name' => 'Posts/Create', '--module' => 'Blog', '-f' => true])
        ->expectsOutputToContain('A form needs a schema')->assertFailed();

    expect($this->blogModule->path.'/resources/js/Pages/Posts/Index.vue')->toBeFile()
        ->and($this->blogModule->path.'/resources/js/Pages/Posts/Create.vue')->not->toBeFile();
});

it('deletes and moves a page with -p', function () {
    $this->artisan('module:vue', ['name' => 'Posts/Index', '--module' => 'Blog', '--page' => true])->assertSuccessful();
    $this->artisan('module:vue:move', ['name' => 'Posts/Index', 'new-name' => 'Posts/List', '--module' => 'Blog', '-p' => true, '--force' => true])->assertSuccessful();

    expect($this->blogModule->path.'/resources/js/Pages/Posts/List.vue')->toBeFile();

    $this->artisan('module:vue:delete', ['name' => 'Posts/List', '--module' => 'Blog', '-p' => true, '--force' => true])->assertSuccessful();

    expect($this->blogModule->path.'/resources/js/Pages/Posts/List.vue')->not->toBeFile();
});
