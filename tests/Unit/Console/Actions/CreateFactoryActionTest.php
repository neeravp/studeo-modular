<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateFactoryAction;
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

test('it creates a factory guessing model from name', function () {
    $action = app(CreateFactoryAction::class);

    $path = $action->execute($this->accountModule, 'Flight');

    expect($path)->toBe($this->accountModule->path.'/database/factories/FlightFactory.php')
        ->and(File::exists($path))->toBeTrue();

    $content = File::get($path);
    expect($content)
        ->toContain('namespace Studeo\Account\Database\Factories;')
        ->toContain('use Studeo\Account\Models\Flight;')
        ->toContain('class FlightFactory extends Factory')
        ->toContain('protected $model = Flight::class;')
        ->toContain('public function definition(): array');
});

test('it preserves explicit Factory suffix in name', function () {
    $action = app(CreateFactoryAction::class);

    $path = $action->execute($this->accountModule, 'FlightFactory');

    expect($path)->toBe($this->accountModule->path.'/database/factories/FlightFactory.php');

    $content = File::get($path);
    expect($content)
        ->toContain('class FlightFactory extends Factory')
        ->toContain('protected $model = Flight::class;')
        ->not->toContain('FlightFactoryFactory');
});

test('it binds explicit model name', function () {
    $action = app(CreateFactoryAction::class);

    $path = $action->execute($this->accountModule, 'AdminUserFactory', 'User');

    expect($path)->toBe($this->accountModule->path.'/database/factories/AdminUserFactory.php');

    $content = File::get($path);
    expect($content)
        ->toContain('class AdminUserFactory extends Factory')
        ->toContain('use Studeo\Account\Models\User;')
        ->toContain('protected $model = User::class;');
});

test('it binds fully-qualified model FQCN', function () {
    $action = app(CreateFactoryAction::class);

    $path = $action->execute($this->accountModule, 'CustomUserFactory', 'App\Models\User');

    $content = File::get($path);
    expect($content)
        ->toContain('class CustomUserFactory extends Factory')
        ->toContain('use App\Models\User;')
        ->toContain('protected $model = User::class;');
});

test('it sanitizes whitespace and slashes in factory name', function () {
    $action = app(CreateFactoryAction::class);

    $path = $action->execute($this->accountModule, '  /passenger/  ');

    expect($path)->toBe($this->accountModule->path.'/database/factories/PassengerFactory.php')
        ->and(File::exists($path))->toBeTrue();
});

test('it throws an exception when module directory does not exist', function () {
    $action = app(CreateFactoryAction::class);

    $ghostModule = new ModuleDefinition(
        name: 'Ghost',
        namespace: 'Studeo\\Ghost',
        path: $this->rootModulesPath.'/Ghost',
    );

    expect(fn () => $action->execute($ghostModule, 'Flight'))
        ->toThrow(RuntimeException::class, "Module directory does not exist [{$ghostModule->path}].");
});

test('it throws an exception when factory already exists in module', function () {
    $action = app(CreateFactoryAction::class);

    $action->execute($this->accountModule, 'Flight');

    expect(fn () => $action->execute($this->accountModule, 'Flight'))
        ->toThrow(RuntimeException::class, 'Factory [FlightFactory] already exists in module [Account].');
});

test('it throws an exception on invalid or empty factory name', function () {
    $action = app(CreateFactoryAction::class);

    expect(fn () => $action->execute($this->accountModule, '   ///   '))
        ->toThrow(RuntimeException::class, 'Invalid factory name [   ///   ].');
});
