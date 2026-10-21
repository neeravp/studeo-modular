<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\Console\Actions\CreateSeederAction;
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

test('it creates a plain seeder when no model is provided', function () {
    $action = app(CreateSeederAction::class);

    $path = $action->execute($this->accountModule, 'DatabaseSeeder');

    expect($path)->toBe($this->accountModule->path.'/database/seeders/DatabaseSeeder.php')
        ->and(File::exists($path))->toBeTrue();

    $content = File::get($path);
    expect($content)
        ->toContain('namespace Studeo\Account\Database\Seeders;')
        ->toContain('use Illuminate\Database\Seeder;')
        ->toContain('class DatabaseSeeder extends Seeder')
        ->toContain('public function run(): void')
        ->not->toContain('use Studeo\Account\Models');
});

test('it seeds the model through its factory when the module has one', function () {
    File::ensureDirectoryExists($this->accountModule->path.'/database/factories');
    File::put($this->accountModule->path.'/database/factories/FlightFactory.php', '<?php');
    $action = app(CreateSeederAction::class);

    $path = $action->execute($this->accountModule, 'FlightSeeder', 'Flight');

    $content = File::get($path);
    expect($content)
        ->toContain('use Studeo\Account\Models\Flight;')
        ->toContain('class FlightSeeder extends Seeder')
        ->toContain("    public function run(): void\n    {\n        Flight::factory()->count(10)->create();\n    }");
});

test('it leaves run() empty and imports no model when the model has no factory', function () {
    $action = app(CreateSeederAction::class);

    $path = $action->execute($this->accountModule, 'FlightSeeder', 'Flight');

    expect(File::get($path))
        ->toContain('public function run(): void {}')
        ->not->toContain('Models\Flight')->not->toContain('//');
});

test('it leaves run() empty for a model outside the module, whose factory it cannot know', function () {
    $action = app(CreateSeederAction::class);

    $path = $action->execute($this->accountModule, 'UserSeeder', 'App\Models\User');

    expect(File::get($path))
        ->toContain('class UserSeeder extends Seeder')
        ->toContain('public function run(): void {}')
        ->not->toContain('App\Models\User');
});

test('it automatically appends Seeder suffix if omitted', function () {
    $action = app(CreateSeederAction::class);

    $path = $action->execute($this->accountModule, 'Flight');

    expect($path)->toBe($this->accountModule->path.'/database/seeders/FlightSeeder.php');

    $content = File::get($path);
    expect($content)->toContain('class FlightSeeder extends Seeder');
});

test('it preserves explicit Seeder suffix', function () {
    $action = app(CreateSeederAction::class);

    $path = $action->execute($this->accountModule, 'FlightSeeder');

    expect($path)->toBe($this->accountModule->path.'/database/seeders/FlightSeeder.php')
        ->not->toContain('FlightSeederSeeder');
});

test('it sanitizes whitespace and slashes in seeder name', function () {
    $action = app(CreateSeederAction::class);

    $path = $action->execute($this->accountModule, '  /passenger/  ');

    expect($path)->toBe($this->accountModule->path.'/database/seeders/PassengerSeeder.php')
        ->and(File::exists($path))->toBeTrue();
});

test('it throws an exception when module directory does not exist', function () {
    $action = app(CreateSeederAction::class);

    $ghostModule = new ModuleDefinition(
        name: 'Ghost',
        namespace: 'Studeo\\Ghost',
        path: $this->rootModulesPath.'/Ghost',
    );

    expect(fn () => $action->execute($ghostModule, 'Flight'))
        ->toThrow(RuntimeException::class, "Module directory does not exist [{$ghostModule->path}].");
});

test('it throws an exception when seeder already exists in module', function () {
    $action = app(CreateSeederAction::class);

    $action->execute($this->accountModule, 'Flight');

    expect(fn () => $action->execute($this->accountModule, 'Flight'))
        ->toThrow(RuntimeException::class, 'Seeder [FlightSeeder] already exists in module [Account].');
});

test('it throws an exception on invalid or empty seeder name', function () {
    $action = app(CreateSeederAction::class);

    expect(fn () => $action->execute($this->accountModule, '   ///   '))
        ->toThrow(RuntimeException::class, 'Invalid seeder name [   ///   ].');
});
