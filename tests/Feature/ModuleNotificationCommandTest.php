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

it('creates a notification with name argument successfully', function () {
    $this->artisan('module:notification', [
        'name' => 'InvoicePaid',
        '--module' => 'Account',
    ])
        ->expectsOutput('Notification [InvoicePaid] created successfully in module [Account].')
        ->assertSuccessful();

    $path = $this->accountModule->path.'/Notifications/InvoicePaid.php';
    expect($path)->toBeFile();

    $content = File::get($path);
    expect($content)
        ->toContain('namespace Studeo\Account\Notifications;')
        ->toContain('class InvoicePaid extends Notification')
        ->not->toContain('implements ShouldQueue');
});

it('creates a queued notification with --queued option successfully', function () {
    $this->artisan('module:notification', [
        'name' => 'InvoicePaid',
        '--module' => 'Account',
        '--queued' => true,
    ])
        ->expectsOutput('Notification [InvoicePaid] created successfully in module [Account].')
        ->assertSuccessful();

    $path = $this->accountModule->path.'/Notifications/InvoicePaid.php';
    expect($path)->toBeFile();

    $content = File::get($path);
    expect($content)
        ->toContain('class InvoicePaid extends Notification implements ShouldQueue');
});

it('creates a notification with default markdown view when --markdown option is passed', function () {
    $this->artisan('module:notification', [
        'name' => 'InvoicePaid',
        '--module' => 'Account',
        '--markdown' => '',
    ])
        ->expectsOutput('Notification [InvoicePaid] created successfully in module [Account].')
        ->assertSuccessful();

    $notificationPath = $this->accountModule->path.'/Notifications/InvoicePaid.php';
    $viewPath = $this->accountModule->path.'/resources/views/mail/invoice-paid.blade.php';

    expect($notificationPath)->toBeFile()
        ->and($viewPath)->toBeFile();

    expect(File::get($notificationPath))->toContain("return (new MailMessage)->markdown('account::mail.invoice-paid');");
    expect(File::get($viewPath))->toContain('<x-mail::message>');
});

it('creates a notification with custom markdown view when --markdown=view is passed', function () {
    $this->artisan('module:notification', [
        'name' => 'InvoicePaid',
        '--module' => 'Account',
        '--markdown' => 'mail.monthly-statement',
    ])
        ->expectsOutput('Notification [InvoicePaid] created successfully in module [Account].')
        ->assertSuccessful();

    $viewPath = $this->accountModule->path.'/resources/views/mail/monthly-statement.blade.php';
    expect($viewPath)->toBeFile();

    $notificationPath = $this->accountModule->path.'/Notifications/InvoicePaid.php';
    expect(File::get($notificationPath))->toContain("return (new MailMessage)->markdown('account::mail.monthly-statement');");
});

it('prompts for notification name when omitted', function () {
    $this->artisan('module:notification', [
        '--module' => 'Account',
    ])
        ->expectsQuestion('What should the notification be named?', 'SecurityAlert')
        ->expectsOutput('Notification [SecurityAlert] created successfully in module [Account].')
        ->assertSuccessful();

    expect($this->accountModule->path.'/Notifications/SecurityAlert.php')->toBeFile();
});

it('prompts for module when --module is omitted', function () {
    $this->writeModuleManifest($this->rootModulesPath.'/Zeta', 'Zeta', 'Studeo\\Zeta');

    $this->artisan('module:notification', [
        'name' => 'PasswordChanged',
    ])
        ->expectsChoice('Which module should this belong to?', 'Account', ['Account', 'Zeta'])
        ->expectsOutput('Notification [PasswordChanged] created successfully in module [Account].')
        ->assertSuccessful();

    expect($this->accountModule->path.'/Notifications/PasswordChanged.php')->toBeFile();
});

it('fails when module does not exist', function () {
    $this->artisan('module:notification', [
        'name' => 'GhostNotification',
        '--module' => 'NonExistent',
    ])
        ->expectsOutputToContain('does not exist')
        ->assertFailed();
});

it('fails when notification already exists', function () {
    $this->artisan('module:notification', [
        'name' => 'DuplicateNotification',
        '--module' => 'Account',
    ])->assertSuccessful();

    $this->artisan('module:notification', [
        'name' => 'DuplicateNotification',
        '--module' => 'Account',
    ])
        ->expectsOutputToContain('Notification [DuplicateNotification] already exists in module [Account].')
        ->assertFailed();
});
