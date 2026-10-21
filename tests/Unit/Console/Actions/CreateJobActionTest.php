<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateJobAction;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\DataObjects\JobGenerationOptions;
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

test('it creates a queued job by default', function () {
    $action = app(CreateJobAction::class);

    $path = $action->execute($this->accountModule, 'ProcessPodcast');

    expect($path)->toBe($this->accountModule->path.'/Jobs/ProcessPodcast.php')
        ->and(File::exists($path))->toBeTrue();

    $content = File::get($path);
    expect($content)
        ->toContain('namespace Studeo\Account\Jobs;')
        ->toContain('use Illuminate\Contracts\Queue\ShouldQueue;')
        ->toContain('use Illuminate\Foundation\Queue\Queueable;')
        ->toContain('class ProcessPodcast implements ShouldQueue')
        ->toContain('use Queueable;')
        ->toContain('public function handle(): void');
});

test('it creates a synchronous job when sync option is true', function () {
    $action = app(CreateJobAction::class);

    $path = $action->execute(
        $this->accountModule,
        'ProcessPodcast',
        new JobGenerationOptions(sync: true)
    );

    $content = File::get($path);
    expect($content)
        ->toContain('namespace Studeo\Account\Jobs;')
        ->toContain('use Illuminate\Foundation\Bus\Dispatchable;')
        ->toContain('class ProcessPodcast')
        ->not->toContain('implements ShouldQueue')
        ->toContain('use Dispatchable;')
        ->toContain('public function handle(): void');
});

test('it creates a batched job when batched option is true', function () {
    $action = app(CreateJobAction::class);

    $path = $action->execute(
        $this->accountModule,
        'ImportUsers',
        new JobGenerationOptions(batched: true)
    );

    $content = File::get($path);
    expect($content)
        ->toContain('namespace Studeo\Account\Jobs;')
        ->toContain('use Illuminate\Bus\Batchable;')
        ->toContain('use Illuminate\Contracts\Queue\ShouldQueue;')
        ->toContain('use Illuminate\Foundation\Queue\Queueable;')
        ->toContain('class ImportUsers implements ShouldQueue')
        ->toContain('use Batchable, Queueable;')
        ->toContain('if ($this->batch()->cancelled()) {');
});

test('it creates Jobs directory on demand if it does not exist', function () {
    File::deleteDirectory($this->accountModule->path.'/Jobs');

    $action = app(CreateJobAction::class);
    $path = $action->execute($this->accountModule, 'GenerateReport');

    expect(File::isDirectory($this->accountModule->path.'/Jobs'))->toBeTrue()
        ->and(File::exists($path))->toBeTrue();
});

test('it sanitizes whitespace and slashes in job name', function () {
    $action = app(CreateJobAction::class);

    $path = $action->execute($this->accountModule, '  /sync_account_balance/  ');

    expect($path)->toBe($this->accountModule->path.'/Jobs/SyncAccountBalance.php')
        ->and(File::exists($path))->toBeTrue();
});

test('it throws an exception when module directory does not exist', function () {
    $action = app(CreateJobAction::class);

    $ghostModule = new ModuleDefinition(
        name: 'Ghost',
        namespace: 'Studeo\\Ghost',
        path: $this->rootModulesPath.'/Ghost',
    );

    expect(fn () => $action->execute($ghostModule, 'ProcessPodcast'))
        ->toThrow(RuntimeException::class, "Module directory does not exist [{$ghostModule->path}].");
});

test('it throws an exception when job already exists in module', function () {
    $action = app(CreateJobAction::class);

    $action->execute($this->accountModule, 'ProcessPodcast');

    expect(fn () => $action->execute($this->accountModule, 'ProcessPodcast'))
        ->toThrow(RuntimeException::class, 'Job [ProcessPodcast] already exists in module [Account].');
});

test('it throws an exception on invalid or empty job name', function () {
    $action = app(CreateJobAction::class);

    expect(fn () => $action->execute($this->accountModule, '   ///   '))
        ->toThrow(RuntimeException::class, 'Invalid job name [   ///   ].');
});
