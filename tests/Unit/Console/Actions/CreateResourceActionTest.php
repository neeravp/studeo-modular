<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\Console\Actions\CreateResourceAction;
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

test('it creates a single JsonResource by default', function () {
    $action = app(CreateResourceAction::class);

    $path = $action->execute($this->accountModule, 'FlightResource');

    expect($path)->toBeFile();

    $content = File::get($path);
    expect($content)
        ->toContain('namespace Studeo\\Account\\ApiResources;')
        ->toContain('use Illuminate\\Http\\Resources\\Json\\JsonResource;')
        ->toContain('class FlightResource extends JsonResource')
        ->toContain('public function toArray(Request $request): array');
});

test('it creates a ResourceCollection when collection flag is true', function () {
    $action = app(CreateResourceAction::class);

    $path = $action->execute($this->accountModule, 'Flight', collection: true);

    expect($path)->toBeFile()
        ->and(basename($path))->toBe('FlightCollection.php');

    $content = File::get($path);
    expect($content)
        ->toContain('namespace Studeo\\Account\\ApiResources;')
        ->toContain('use Illuminate\\Http\\Resources\\Json\\ResourceCollection;')
        ->toContain('class FlightCollection extends ResourceCollection')
        ->toContain('public function toArray(Request $request): array');
});

test('it auto-detects collection when name ends with Collection', function () {
    $action = app(CreateResourceAction::class);

    $path = $action->execute($this->accountModule, 'FlightCollection');

    expect(basename($path))->toBe('FlightCollection.php');

    $content = File::get($path);
    expect($content)
        ->toContain('class FlightCollection extends ResourceCollection');
});

test('it automatically appends Resource suffix if omitted', function () {
    $action = app(CreateResourceAction::class);

    $path = $action->execute($this->accountModule, 'Booking');

    expect(basename($path))->toBe('BookingResource.php')
        ->and(File::get($path))->toContain('class BookingResource extends JsonResource');
});

test('it sanitizes whitespace and slashes in resource name', function () {
    $action = app(CreateResourceAction::class);

    $path = $action->execute($this->accountModule, ' /flight/ ');

    expect(basename($path))->toBe('FlightResource.php');
});

test('it throws when module directory does not exist on disk', function () {
    $ghostModule = new ModuleDefinition(
        name: 'Ghost',
        namespace: 'Studeo\\Ghost',
        path: $this->rootModulesPath.'/Ghost',
    );

    $action = app(CreateResourceAction::class);

    expect(fn () => $action->execute($ghostModule, 'FlightResource'))
        ->toThrow(
            RuntimeException::class,
            "Module directory does not exist [{$ghostModule->path}]."
        );
});

test('it throws when resource already exists in module', function () {
    $action = app(CreateResourceAction::class);

    $action->execute($this->accountModule, 'FlightResource');

    expect(fn () => $action->execute($this->accountModule, 'FlightResource'))
        ->toThrow(
            RuntimeException::class,
            'Resource [FlightResource] already exists in module [Account].'
        );
});

test('it throws when resource name is invalid or empty', function () {
    $action = app(CreateResourceAction::class);

    expect(fn () => $action->execute($this->accountModule, '///'))
        ->toThrow(
            RuntimeException::class,
            'Invalid resource name [///].'
        );
});
