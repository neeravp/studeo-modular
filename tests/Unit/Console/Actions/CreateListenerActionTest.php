<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateListenerAction;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\DataObjects\ListenerGenerationOptions;
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

test('it creates a generic listener when no event is provided', function () {
    $action = app(CreateListenerAction::class);

    $path = $action->execute($this->accountModule, 'SendOrderNotification');

    expect($path)->toBe($this->accountModule->path.'/Listeners/SendOrderNotification.php')
        ->and(File::exists($path))->toBeTrue();

    $content = File::get($path);
    expect($content)
        ->toContain('namespace Studeo\Account\Listeners;')
        ->toContain('class SendOrderNotification')
        ->toContain('public function handle(object $event): void')
        ->not->toContain('implements ShouldQueue');
});

test('it creates a listener bound to a module event', function () {
    $action = app(CreateListenerAction::class);

    $path = $action->execute(
        $this->accountModule,
        'SendOrderNotification',
        new ListenerGenerationOptions(event: 'OrderPlaced')
    );

    $content = File::get($path);
    expect($content)
        ->toContain('use Studeo\Account\Events\OrderPlaced;')
        ->toContain('class SendOrderNotification')
        ->toContain('public function handle(OrderPlaced $event): void');
});

test('it creates a listener bound to an external FQCN event', function () {
    $action = app(CreateListenerAction::class);

    $path = $action->execute(
        $this->accountModule,
        'LogUserLogin',
        new ListenerGenerationOptions(event: 'App\Events\UserLoggedIn')
    );

    $content = File::get($path);
    expect($content)
        ->toContain('use App\Events\UserLoggedIn;')
        ->toContain('public function handle(UserLoggedIn $event): void');
});

test('it creates a queued listener when queued option is true', function () {
    $action = app(CreateListenerAction::class);

    $path = $action->execute(
        $this->accountModule,
        'ProcessOrderPayment',
        new ListenerGenerationOptions(event: 'OrderPlaced', queued: true)
    );

    $content = File::get($path);
    expect($content)
        ->toContain('use Illuminate\Contracts\Queue\ShouldQueue;')
        ->toContain('use Illuminate\Queue\InteractsWithQueue;')
        ->toContain('use Studeo\Account\Events\OrderPlaced;')
        ->toContain('class ProcessOrderPayment implements ShouldQueue')
        ->toContain('use InteractsWithQueue;')
        ->toContain('public function handle(OrderPlaced $event): void');
});

test('it creates Listeners directory on demand if it does not exist', function () {
    File::deleteDirectory($this->accountModule->path.'/Listeners');

    $action = app(CreateListenerAction::class);
    $path = $action->execute($this->accountModule, 'AuditAccountAccess');

    expect(File::isDirectory($this->accountModule->path.'/Listeners'))->toBeTrue()
        ->and(File::exists($path))->toBeTrue();
});

test('it sanitizes whitespace and slashes in listener name', function () {
    $action = app(CreateListenerAction::class);

    $path = $action->execute($this->accountModule, '  /handle_cancellation/  ');

    expect($path)->toBe($this->accountModule->path.'/Listeners/HandleCancellation.php')
        ->and(File::exists($path))->toBeTrue();
});

test('it throws an exception when module directory does not exist', function () {
    $action = app(CreateListenerAction::class);

    $ghostModule = new ModuleDefinition(
        name: 'Ghost',
        namespace: 'Studeo\\Ghost',
        path: $this->rootModulesPath.'/Ghost',
    );

    expect(fn () => $action->execute($ghostModule, 'SendWelcomeEmail'))
        ->toThrow(RuntimeException::class, "Module directory does not exist [{$ghostModule->path}].");
});

test('it throws an exception when listener already exists in module', function () {
    $action = app(CreateListenerAction::class);

    $action->execute($this->accountModule, 'SendOrderNotification');

    expect(fn () => $action->execute($this->accountModule, 'SendOrderNotification'))
        ->toThrow(RuntimeException::class, 'Listener [SendOrderNotification] already exists in module [Account].');
});

test('it throws an exception on invalid or empty listener name', function () {
    $action = app(CreateListenerAction::class);

    expect(fn () => $action->execute($this->accountModule, '   ///   '))
        ->toThrow(RuntimeException::class, 'Invalid listener name [   ///   ].');
});
