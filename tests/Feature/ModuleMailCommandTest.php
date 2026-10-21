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

it('creates a mailable with name argument successfully', function () {
    $this->artisan('module:mail', [
        'name' => 'OrderShipped',
        '--module' => 'Account',
    ])
        ->expectsOutput('Mail [OrderShipped] created successfully in module [Account].')
        ->assertSuccessful();

    $path = $this->accountModule->path.'/Mail/OrderShipped.php';
    expect($path)->toBeFile();

    $content = File::get($path);
    expect($content)
        ->toContain('namespace Studeo\Account\Mail;')
        ->toContain('class OrderShipped extends Mailable')
        ->not->toContain('implements ShouldQueue')
        ->toContain("subject: 'Order Shipped',");
});

it('creates a queued mailable with --queued option successfully', function () {
    $this->artisan('module:mail', [
        'name' => 'OrderShipped',
        '--module' => 'Account',
        '--queued' => true,
    ])
        ->expectsOutput('Mail [OrderShipped] created successfully in module [Account].')
        ->assertSuccessful();

    $path = $this->accountModule->path.'/Mail/OrderShipped.php';
    expect($path)->toBeFile();

    $content = File::get($path);
    expect($content)
        ->toContain('class OrderShipped extends Mailable implements ShouldQueue');
});

it('creates a mailable with markdown view when --markdown option is passed', function () {
    $this->artisan('module:mail', [
        'name' => 'OrderShipped',
        '--module' => 'Account',
        '--markdown' => true,
    ])
        ->expectsOutput('Mail [OrderShipped] created successfully in module [Account].')
        ->assertSuccessful();

    $mailPath = $this->accountModule->path.'/Mail/OrderShipped.php';
    $viewPath = $this->accountModule->path.'/resources/views/mail/order-shipped.blade.php';

    expect($mailPath)->toBeFile()
        ->and($viewPath)->toBeFile();

    expect(File::get($mailPath))->toContain("markdown: 'account::mail.order-shipped'");
    expect(File::get($viewPath))->toContain('<x-mail::message>');
});

it('creates a mailable with plain view when --view option is passed', function () {
    $this->artisan('module:mail', [
        'name' => 'OrderShipped',
        '--module' => 'Account',
        '--view' => 'mail.custom-order',
    ])
        ->expectsOutput('Mail [OrderShipped] created successfully in module [Account].')
        ->assertSuccessful();

    $viewPath = $this->accountModule->path.'/resources/views/mail/custom-order.blade.php';
    expect($viewPath)->toBeFile();

    $mailPath = $this->accountModule->path.'/Mail/OrderShipped.php';
    expect(File::get($mailPath))->toContain("view: 'account::mail.custom-order'");
    expect(File::get($viewPath))->toContain('<!-- Mail content -->');
});

it('prompts for mail name when omitted', function () {
    $this->artisan('module:mail', [
        '--module' => 'Account',
    ])
        ->expectsQuestion('What should the mailable be named?', 'MonthlyDigest')
        ->expectsOutput('Mail [MonthlyDigest] created successfully in module [Account].')
        ->assertSuccessful();

    expect($this->accountModule->path.'/Mail/MonthlyDigest.php')->toBeFile();
});

it('prompts for module when --module is omitted', function () {
    $this->writeModuleManifest($this->rootModulesPath.'/Zeta', 'Zeta', 'Studeo\\Zeta');

    $this->artisan('module:mail', [
        'name' => 'AccountWelcome',
    ])
        ->expectsChoice('Which module should this belong to?', 'Account', ['Account', 'Zeta'])
        ->expectsOutput('Mail [AccountWelcome] created successfully in module [Account].')
        ->assertSuccessful();

    expect($this->accountModule->path.'/Mail/AccountWelcome.php')->toBeFile();
});

it('fails when module does not exist', function () {
    $this->artisan('module:mail', [
        'name' => 'GhostMail',
        '--module' => 'NonExistent',
    ])
        ->expectsOutputToContain('does not exist')
        ->assertFailed();
});

it('fails when mail already exists', function () {
    $this->artisan('module:mail', [
        'name' => 'DuplicateMail',
        '--module' => 'Account',
    ])->assertSuccessful();

    $this->artisan('module:mail', [
        'name' => 'DuplicateMail',
        '--module' => 'Account',
    ])
        ->expectsOutputToContain('Mail [DuplicateMail] already exists in module [Account].')
        ->assertFailed();
});
