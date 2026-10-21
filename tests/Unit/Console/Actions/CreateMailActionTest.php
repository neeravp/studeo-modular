<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateMailAction;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\DataObjects\MailGenerationOptions;
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

test('it creates a plain mailable without view scaffolding by default', function () {
    $action = app(CreateMailAction::class);

    $result = $action->execute($this->accountModule, 'OrderShipped');

    expect($result->mailPath)->toBe($this->accountModule->path.'/Mail/OrderShipped.php')
        ->and($result->viewPath)->toBeNull()
        ->and(File::exists($result->mailPath))->toBeTrue();

    $content = File::get($result->mailPath);
    expect($content)
        ->toContain('namespace Studeo\Account\Mail;')
        ->toContain('class OrderShipped extends Mailable')
        ->not->toContain('implements ShouldQueue')
        ->toContain("subject: 'Order Shipped',")
        ->toContain("view: 'account::mail.order-shipped',");
});

test('it creates a queued mailable when queued option is true', function () {
    $action = app(CreateMailAction::class);

    $result = $action->execute(
        $this->accountModule,
        'OrderShipped',
        new MailGenerationOptions(queued: true)
    );

    $content = File::get($result->mailPath);
    expect($content)
        ->toContain('class OrderShipped extends Mailable implements ShouldQueue');
});

test('it creates a mailable with markdown view when markdown option is empty string', function () {
    $action = app(CreateMailAction::class);

    $result = $action->execute(
        $this->accountModule,
        'OrderShipped',
        new MailGenerationOptions(markdown: '')
    );

    $expectedViewPath = $this->accountModule->path.'/resources/views/mail/order-shipped.blade.php';

    expect($result->viewPath)->toBe($expectedViewPath)
        ->and(File::exists($result->mailPath))->toBeTrue()
        ->and(File::exists($expectedViewPath))->toBeTrue();

    $mailContent = File::get($result->mailPath);
    expect($mailContent)
        ->toContain("markdown: 'account::mail.order-shipped',");

    $viewContent = File::get($expectedViewPath);
    expect($viewContent)
        ->toContain('<x-mail::message>');
});

test('it creates a mailable with custom markdown view when explicit string is provided', function () {
    $action = app(CreateMailAction::class);

    $result = $action->execute(
        $this->accountModule,
        'OrderShipped',
        new MailGenerationOptions(markdown: 'mail.custom-shipping')
    );

    $expectedViewPath = $this->accountModule->path.'/resources/views/mail/custom-shipping.blade.php';

    expect($result->viewPath)->toBe($expectedViewPath)
        ->and(File::exists($expectedViewPath))->toBeTrue();

    $mailContent = File::get($result->mailPath);
    expect($mailContent)
        ->toContain("markdown: 'account::mail.custom-shipping',");
});

test('it creates a mailable with plain view when view option is empty string', function () {
    $action = app(CreateMailAction::class);

    $result = $action->execute(
        $this->accountModule,
        'OrderShipped',
        new MailGenerationOptions(view: '')
    );

    $expectedViewPath = $this->accountModule->path.'/resources/views/mail/order-shipped.blade.php';

    expect($result->viewPath)->toBe($expectedViewPath)
        ->and(File::exists($expectedViewPath))->toBeTrue();

    $mailContent = File::get($result->mailPath);
    expect($mailContent)
        ->toContain("view: 'account::mail.order-shipped',");

    $viewContent = File::get($expectedViewPath);
    expect($viewContent)
        ->toContain('<!-- Mail content -->');
});

test('it strips trailing Mail suffix when deriving subject and default view', function () {
    $action = app(CreateMailAction::class);

    $result = $action->execute($this->accountModule, 'OrderShippedMail');

    $content = File::get($result->mailPath);
    expect($content)
        ->toContain("subject: 'Order Shipped',")
        ->toContain("view: 'account::mail.order-shipped',");
});

test('it creates Mail directory on demand if it does not exist', function () {
    File::deleteDirectory($this->accountModule->path.'/Mail');

    $action = app(CreateMailAction::class);
    $result = $action->execute($this->accountModule, 'MonthlyDigest');

    expect(File::isDirectory($this->accountModule->path.'/Mail'))->toBeTrue()
        ->and(File::exists($result->mailPath))->toBeTrue();
});

test('it sanitizes whitespace and slashes in mail name', function () {
    $action = app(CreateMailAction::class);

    $result = $action->execute($this->accountModule, '  /welcome_customer/  ');

    expect($result->mailPath)->toBe($this->accountModule->path.'/Mail/WelcomeCustomer.php')
        ->and(File::exists($result->mailPath))->toBeTrue();
});

test('it throws an exception when module directory does not exist', function () {
    $action = app(CreateMailAction::class);

    $ghostModule = new ModuleDefinition(
        name: 'Ghost',
        namespace: 'Studeo\\Ghost',
        path: $this->rootModulesPath.'/Ghost',
    );

    expect(fn () => $action->execute($ghostModule, 'OrderShipped'))
        ->toThrow(RuntimeException::class, "Module directory does not exist [{$ghostModule->path}].");
});

test('it throws an exception when mail already exists in module', function () {
    $action = app(CreateMailAction::class);

    $action->execute($this->accountModule, 'OrderShipped');

    expect(fn () => $action->execute($this->accountModule, 'OrderShipped'))
        ->toThrow(RuntimeException::class, 'Mail [OrderShipped] already exists in module [Account].');
});

test('it throws an exception on invalid or empty mail name', function () {
    $action = app(CreateMailAction::class);

    expect(fn () => $action->execute($this->accountModule, '   ///   '))
        ->toThrow(RuntimeException::class, 'Invalid mail name [   ///   ].');
});
