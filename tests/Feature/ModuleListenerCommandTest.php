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

it('creates a listener with name argument successfully', function () {
    $this->artisan('module:listener', [
        'name' => 'SendOrderNotification',
        '--module' => 'Account',
    ])
        ->expectsOutput('Listener [SendOrderNotification] created successfully in module [Account].')
        ->assertSuccessful();

    $path = $this->accountModule->path.'/Listeners/SendOrderNotification.php';
    expect($path)->toBeFile();

    $content = File::get($path);
    expect($content)
        ->toContain('namespace Studeo\Account\Listeners;')
        ->toContain('class SendOrderNotification')
        ->toContain('public function handle(object $event): void');
});

it('creates a listener with --event option successfully', function () {
    $this->artisan('module:listener', [
        'name' => 'SendOrderNotification',
        '--module' => 'Account',
        '--event' => 'OrderPlaced',
    ])
        ->expectsOutput('Listener [SendOrderNotification] created successfully in module [Account].')
        ->assertSuccessful();

    $path = $this->accountModule->path.'/Listeners/SendOrderNotification.php';
    expect($path)->toBeFile();

    $content = File::get($path);
    expect($content)
        ->toContain('use Studeo\Account\Events\OrderPlaced;')
        ->toContain('public function handle(OrderPlaced $event): void');
});

it('creates a queued listener with --queued option successfully', function () {
    $this->artisan('module:listener', [
        'name' => 'ProcessOrderPayment',
        '--module' => 'Account',
        '--event' => 'OrderPlaced',
        '--queued' => true,
    ])
        ->expectsOutput('Listener [ProcessOrderPayment] created successfully in module [Account].')
        ->assertSuccessful();

    $path = $this->accountModule->path.'/Listeners/ProcessOrderPayment.php';
    expect($path)->toBeFile();

    $content = File::get($path);
    expect($content)
        ->toContain('use Illuminate\Contracts\Queue\ShouldQueue;')
        ->toContain('use Illuminate\Queue\InteractsWithQueue;')
        ->toContain('class ProcessOrderPayment implements ShouldQueue')
        ->toContain('use InteractsWithQueue;')
        ->toContain('public function handle(OrderPlaced $event): void');
});

it('prompts for listener name when omitted', function () {
    $this->artisan('module:listener', [
        '--module' => 'Account',
    ])
        ->expectsQuestion('What should the listener be named?', 'LogAccountAccess')
        ->expectsOutput('Listener [LogAccountAccess] created successfully in module [Account].')
        ->assertSuccessful();

    expect($this->accountModule->path.'/Listeners/LogAccountAccess.php')->toBeFile();
});

it('prompts for module when --module is omitted', function () {
    $this->writeModuleManifest($this->rootModulesPath.'/Zeta', 'Zeta', 'Studeo\\Zeta');

    $this->artisan('module:listener', [
        'name' => 'AuditSecurityEvent',
    ])
        ->expectsChoice('Which module should this belong to?', 'Account', ['Account', 'Zeta'])
        ->expectsOutput('Listener [AuditSecurityEvent] created successfully in module [Account].')
        ->assertSuccessful();

    expect($this->accountModule->path.'/Listeners/AuditSecurityEvent.php')->toBeFile();
});

it('fails when module does not exist', function () {
    $this->artisan('module:listener', [
        'name' => 'GhostListener',
        '--module' => 'NonExistent',
    ])
        ->expectsOutputToContain('does not exist')
        ->assertFailed();
});

it('fails when listener already exists', function () {
    $this->artisan('module:listener', [
        'name' => 'DuplicateListener',
        '--module' => 'Account',
    ])->assertSuccessful();

    $this->artisan('module:listener', [
        'name' => 'DuplicateListener',
        '--module' => 'Account',
    ])
        ->expectsOutputToContain('Listener [DuplicateListener] already exists in module [Account].')
        ->assertFailed();
});
