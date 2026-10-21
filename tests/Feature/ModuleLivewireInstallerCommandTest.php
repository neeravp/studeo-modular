<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

const LIVEWIRE_QUESTION = 'Livewire 4 is not installed in this project. Install it with composer require livewire/livewire:^4.0?';

beforeEach(function () {
    $this->rootModulesPath = config('modular.root_path');
    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);
    $this->module = new ModuleDefinition('Blog', 'Studeo\\Blog', $this->rootModulesPath.'/Blog');
    app(CreateModuleAction::class)->execute($this->module);
    $this->skeleton = skeletonFrontendSnapshot();
    fakeLivewireInstalled(false);
    Process::fake();
});

afterEach(function () {
    expect(skeletonFrontendSnapshot())->toBe($this->skeleton);
    File::deleteDirectory($this->rootModulesPath);
});

it('requires Livewire after a yes', function () {
    $this->artisan('module:livewire', ['name' => 'PostList', '--module' => 'Blog'])
        ->expectsConfirmation(LIVEWIRE_QUESTION, 'yes')
        ->assertSuccessful();

    Process::assertRan(fn ($process) => $process->command === ['composer', 'require', 'livewire/livewire:^4.0']);
    expect($this->module->path.'/Livewire/PostList.php')->toBeFile();
});

it('runs nothing after a no and prints the command', function () {
    $this->artisan('module:livewire', ['name' => 'PostList', '--module' => 'Blog'])
        ->expectsConfirmation(LIVEWIRE_QUESTION, 'no')
        ->expectsOutputToContain('composer require livewire/livewire:^4.0')
        ->assertFailed();

    Process::assertNothingRan();
    expect($this->module->path.'/Livewire/PostList.php')->not->toBeFile();
});

it('runs nothing and prints the command when it cannot prompt', function () {
    $status = Artisan::call('module:livewire', ['name' => 'PostList', '--module' => 'Blog']);

    expect($status)->toBe(1)
        ->and(Artisan::output())->toContain('composer require livewire/livewire:^4.0')
        ->and($this->module->path.'/Livewire/PostList.php')->not->toBeFile();
    Process::assertNothingRan();
});

it('asks nothing when Livewire is installed', function () {
    fakeLivewireInstalled();

    $this->artisan('module:livewire', ['name' => 'PostList', '--module' => 'Blog'])->assertSuccessful();

    Process::assertNothingRan();
});
