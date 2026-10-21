<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\Console\Actions\CreateNotificationAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\NotificationGenerationOptions;
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

test('it creates a notification without markdown by default', function () {
    $action = app(CreateNotificationAction::class);

    $result = $action->execute($this->accountModule, 'InvoicePaid');

    expect($result->notificationPath)->toBe($this->accountModule->path.'/Notifications/InvoicePaid.php')
        ->and($result->viewPath)->toBeNull()
        ->and(File::exists($result->notificationPath))->toBeTrue();

    $content = File::get($result->notificationPath);
    expect($content)
        ->toContain('namespace Studeo\Account\Notifications;')
        ->toContain('class InvoicePaid extends Notification')
        ->not->toContain('implements ShouldQueue')
        ->toContain('public function via(object $notifiable): array')
        ->toContain('public function toMail(object $notifiable): MailMessage')
        ->toContain('return new MailMessage;')
        ->toContain('public function toArray(object $notifiable): array');
});

test('it creates a queued notification when queued option is true', function () {
    $action = app(CreateNotificationAction::class);

    $result = $action->execute(
        $this->accountModule,
        'InvoicePaid',
        new NotificationGenerationOptions(queued: true)
    );

    $content = File::get($result->notificationPath);
    expect($content)
        ->toContain('class InvoicePaid extends Notification implements ShouldQueue');
});

test('it creates a notification with default markdown view when markdown option is empty string', function () {
    $action = app(CreateNotificationAction::class);

    $result = $action->execute(
        $this->accountModule,
        'InvoicePaid',
        new NotificationGenerationOptions(markdown: '')
    );

    $expectedViewPath = $this->accountModule->path.'/resources/views/mail/invoice-paid.blade.php';

    expect($result->viewPath)->toBe($expectedViewPath)
        ->and(File::exists($result->notificationPath))->toBeTrue()
        ->and(File::exists($expectedViewPath))->toBeTrue();

    $notificationContent = File::get($result->notificationPath);
    expect($notificationContent)
        ->toContain("return (new MailMessage)->markdown('account::mail.invoice-paid');");

    $viewContent = File::get($expectedViewPath);
    expect($viewContent)
        ->toContain('<x-mail::message>');
});

test('it strips trailing Notification suffix when deriving default markdown view name', function () {
    $action = app(CreateNotificationAction::class);

    $result = $action->execute(
        $this->accountModule,
        'InvoicePaidNotification',
        new NotificationGenerationOptions(markdown: '')
    );

    $expectedViewPath = $this->accountModule->path.'/resources/views/mail/invoice-paid.blade.php';

    expect($result->viewPath)->toBe($expectedViewPath)
        ->and(File::exists($expectedViewPath))->toBeTrue();

    $notificationContent = File::get($result->notificationPath);
    expect($notificationContent)
        ->toContain("return (new MailMessage)->markdown('account::mail.invoice-paid');");
});

test('it creates a notification with custom markdown view when explicit string is provided', function () {
    $action = app(CreateNotificationAction::class);

    $result = $action->execute(
        $this->accountModule,
        'InvoicePaid',
        new NotificationGenerationOptions(markdown: 'mail.custom-invoice')
    );

    $expectedViewPath = $this->accountModule->path.'/resources/views/mail/custom-invoice.blade.php';

    expect($result->viewPath)->toBe($expectedViewPath)
        ->and(File::exists($expectedViewPath))->toBeTrue();

    $notificationContent = File::get($result->notificationPath);
    expect($notificationContent)
        ->toContain("return (new MailMessage)->markdown('account::mail.custom-invoice');");
});

test('it creates Notifications directory on demand if it does not exist', function () {
    File::deleteDirectory($this->accountModule->path.'/Notifications');

    $action = app(CreateNotificationAction::class);
    $result = $action->execute($this->accountModule, 'PaymentReceipt');

    expect(File::isDirectory($this->accountModule->path.'/Notifications'))->toBeTrue()
        ->and(File::exists($result->notificationPath))->toBeTrue();
});

test('it sanitizes whitespace and slashes in notification name', function () {
    $action = app(CreateNotificationAction::class);

    $result = $action->execute($this->accountModule, '  /payment_failed/  ');

    expect($result->notificationPath)->toBe($this->accountModule->path.'/Notifications/PaymentFailed.php')
        ->and(File::exists($result->notificationPath))->toBeTrue();
});

test('it throws an exception when module directory does not exist', function () {
    $action = app(CreateNotificationAction::class);

    $ghostModule = new ModuleDefinition(
        name: 'Ghost',
        namespace: 'Studeo\\Ghost',
        path: $this->rootModulesPath.'/Ghost',
    );

    expect(fn () => $action->execute($ghostModule, 'InvoicePaid'))
        ->toThrow(RuntimeException::class, "Module directory does not exist [{$ghostModule->path}].");
});

test('it throws an exception when notification already exists in module', function () {
    $action = app(CreateNotificationAction::class);

    $action->execute($this->accountModule, 'InvoicePaid');

    expect(fn () => $action->execute($this->accountModule, 'InvoicePaid'))
        ->toThrow(RuntimeException::class, 'Notification [InvoicePaid] already exists in module [Account].');
});

test('it throws an exception on invalid or empty notification name', function () {
    $action = app(CreateNotificationAction::class);

    expect(fn () => $action->execute($this->accountModule, '   ///   '))
        ->toThrow(RuntimeException::class, 'Invalid notification name [   ///   ].');
});
