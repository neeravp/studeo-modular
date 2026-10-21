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

it('creates a command with name argument successfully', function () {
    $this->artisan('module:command', [
        'name' => 'SendInvoices',
        '--module' => 'Account',
    ])
        ->expectsOutput('Command [SendInvoices] created successfully in module [Account].')
        ->assertSuccessful();

    $path = $this->accountModule->path.'/Commands/SendInvoices.php';
    expect($path)->toBeFile();

    $content = File::get($path);
    expect($content)
        ->toContain('namespace Studeo\Account\Commands;')
        ->toContain('class SendInvoices extends Command')
        ->toContain("protected \$signature = 'account:send-invoices';");
});

it('creates a command with explicit --command signature successfully', function () {
    $this->artisan('module:command', [
        'name' => 'SendInvoices',
        '--module' => 'Account',
        '--command' => 'invoices:dispatch',
    ])
        ->expectsOutput('Command [SendInvoices] created successfully in module [Account].')
        ->assertSuccessful();

    $path = $this->accountModule->path.'/Commands/SendInvoices.php';
    expect($path)->toBeFile();

    $content = File::get($path);
    expect($content)
        ->toContain("protected \$signature = 'invoices:dispatch';");
});

it('prompts for command name when omitted', function () {
    $this->artisan('module:command', [
        '--module' => 'Account',
    ])
        ->expectsQuestion('What should the command be named?', 'SyncUserData')
        ->expectsOutput('Command [SyncUserData] created successfully in module [Account].')
        ->assertSuccessful();

    expect($this->accountModule->path.'/Commands/SyncUserData.php')->toBeFile();
});

it('prompts for module when --module is omitted', function () {
    $this->writeModuleManifest($this->rootModulesPath.'/Zeta', 'Zeta', 'Studeo\\Zeta');

    $this->artisan('module:command', [
        'name' => 'AuditSecurityAlerts',
    ])
        ->expectsChoice('Which module should this belong to?', 'Account', ['Account', 'Zeta'])
        ->expectsOutput('Command [AuditSecurityAlerts] created successfully in module [Account].')
        ->assertSuccessful();

    expect($this->accountModule->path.'/Commands/AuditSecurityAlerts.php')->toBeFile();
});

it('fails when module does not exist', function () {
    $this->artisan('module:command', [
        'name' => 'GhostCommand',
        '--module' => 'NonExistent',
    ])
        ->expectsOutputToContain('does not exist')
        ->assertFailed();
});

it('fails when command already exists', function () {
    $this->artisan('module:command', [
        'name' => 'DuplicateCommand',
        '--module' => 'Account',
    ])->assertSuccessful();

    $this->artisan('module:command', [
        'name' => 'DuplicateCommand',
        '--module' => 'Account',
    ])
        ->expectsOutputToContain('Command [DuplicateCommand] already exists in module [Account].')
        ->assertFailed();
});
