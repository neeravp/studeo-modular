<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateEventAction;
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

test('it creates an event with given name', function () {
    $action = app(CreateEventAction::class);

    $path = $action->execute($this->accountModule, 'OrderPlaced');

    expect($path)->toBe($this->accountModule->path.'/Events/OrderPlaced.php')
        ->and(File::exists($path))->toBeTrue();

    $content = File::get($path);
    expect($content)
        ->toContain('namespace Studeo\Account\Events;')
        ->toContain('class OrderPlaced')
        ->toContain('use Dispatchable, InteractsWithSockets, SerializesModels;');
});

test('it preserves Event suffix if present', function () {
    $action = app(CreateEventAction::class);

    $path = $action->execute($this->accountModule, 'OrderPlacedEvent');

    expect($path)->toBe($this->accountModule->path.'/Events/OrderPlacedEvent.php');

    $content = File::get($path);
    expect($content)->toContain('class OrderPlacedEvent');
});

test('it creates Events directory on demand if it does not exist', function () {
    File::deleteDirectory($this->accountModule->path.'/Events');

    $action = app(CreateEventAction::class);
    $path = $action->execute($this->accountModule, 'InvoicePaid');

    expect(File::isDirectory($this->accountModule->path.'/Events'))->toBeTrue()
        ->and(File::exists($path))->toBeTrue();
});

test('it sanitizes whitespace and slashes in event name', function () {
    $action = app(CreateEventAction::class);

    $path = $action->execute($this->accountModule, '  /user_registered/  ');

    expect($path)->toBe($this->accountModule->path.'/Events/UserRegistered.php')
        ->and(File::exists($path))->toBeTrue();
});

test('it throws an exception when module directory does not exist', function () {
    $action = app(CreateEventAction::class);

    $ghostModule = new ModuleDefinition(
        name: 'Ghost',
        namespace: 'Studeo\\Ghost',
        path: $this->rootModulesPath.'/Ghost',
    );

    expect(fn () => $action->execute($ghostModule, 'OrderPlaced'))
        ->toThrow(RuntimeException::class, "Module directory does not exist [{$ghostModule->path}].");
});

test('it throws an exception when event already exists in module', function () {
    $action = app(CreateEventAction::class);

    $action->execute($this->accountModule, 'OrderPlaced');

    expect(fn () => $action->execute($this->accountModule, 'OrderPlaced'))
        ->toThrow(RuntimeException::class, 'Event [OrderPlaced] already exists in module [Account].');
});

test('it throws an exception on invalid or empty event name', function () {
    $action = app(CreateEventAction::class);

    expect(fn () => $action->execute($this->accountModule, '   ///   '))
        ->toThrow(RuntimeException::class, 'Invalid event name [   ///   ].');
});
