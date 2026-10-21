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

    $this->accountModule = new ModuleDefinition(
        name: 'Account',
        namespace: 'Studeo\\Account',
        path: $this->rootModulesPath.'/Account',
    );

    app(CreateModuleAction::class)->execute($this->accountModule);
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

it('creates a queued job with name argument successfully', function () {
    $this->artisan('module:job', [
        'name' => 'ProcessPodcast',
        '--module' => 'Account',
    ])
        ->expectsOutput('Job [ProcessPodcast] created successfully in module [Account].')
        ->assertSuccessful();

    $path = $this->accountModule->path.'/Jobs/ProcessPodcast.php';
    expect($path)->toBeFile();

    $content = File::get($path);
    expect($content)
        ->toContain('namespace Studeo\Account\Jobs;')
        ->toContain('class ProcessPodcast implements ShouldQueue')
        ->toContain('use Queueable;');
});

it('creates a synchronous job with --sync option successfully', function () {
    $this->artisan('module:job', [
        'name' => 'ProcessPodcast',
        '--module' => 'Account',
        '--sync' => true,
    ])
        ->expectsOutput('Job [ProcessPodcast] created successfully in module [Account].')
        ->assertSuccessful();

    $path = $this->accountModule->path.'/Jobs/ProcessPodcast.php';
    expect($path)->toBeFile();

    $content = File::get($path);
    expect($content)
        ->toContain('class ProcessPodcast')
        ->not->toContain('implements ShouldQueue')
        ->toContain('use Dispatchable;');
});

it('creates a batched job with --batched option successfully', function () {
    $this->artisan('module:job', [
        'name' => 'ImportUsers',
        '--module' => 'Account',
        '--batched' => true,
    ])
        ->expectsOutput('Job [ImportUsers] created successfully in module [Account].')
        ->assertSuccessful();

    $path = $this->accountModule->path.'/Jobs/ImportUsers.php';
    expect($path)->toBeFile();

    $content = File::get($path);
    expect($content)
        ->toContain('class ImportUsers implements ShouldQueue')
        ->toContain('use Batchable, Queueable;');
});

it('prompts for job name when omitted', function () {
    $this->artisan('module:job', [
        '--module' => 'Account',
    ])
        ->expectsQuestion('What should the job be named?', 'SendWeeklyDigest')
        ->expectsOutput('Job [SendWeeklyDigest] created successfully in module [Account].')
        ->assertSuccessful();

    expect($this->accountModule->path.'/Jobs/SendWeeklyDigest.php')->toBeFile();
});

it('prompts for module when --module is omitted', function () {
    $this->writeModuleManifest($this->rootModulesPath.'/Zeta', 'Zeta', 'Studeo\\Zeta');

    $this->artisan('module:job', [
        'name' => 'CleanupOrphanFiles',
    ])
        ->expectsChoice('Which module should this belong to?', 'Account', ['Account', 'Zeta'])
        ->expectsOutput('Job [CleanupOrphanFiles] created successfully in module [Account].')
        ->assertSuccessful();

    expect($this->accountModule->path.'/Jobs/CleanupOrphanFiles.php')->toBeFile();
});

it('fails when module does not exist', function () {
    $this->artisan('module:job', [
        'name' => 'GhostJob',
        '--module' => 'NonExistent',
    ])
        ->expectsOutputToContain('does not exist')
        ->assertFailed();
});

it('fails when job already exists', function () {
    $this->artisan('module:job', [
        'name' => 'DuplicateJob',
        '--module' => 'Account',
    ])->assertSuccessful();

    $this->artisan('module:job', [
        'name' => 'DuplicateJob',
        '--module' => 'Account',
    ])
        ->expectsOutputToContain('Job [DuplicateJob] already exists in module [Account].')
        ->assertFailed();
});
