<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModelAction;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\DataObjects\ModelGenerationOptions;
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

test('it creates model, migration, factory, and seeder by default', function () {
    $action = app(CreateModelAction::class);

    $result = $action->execute($this->accountModule, 'Flight');

    expect($result->modelPath)->toBeFile()
        ->and($result->migrationPath)->toBeFile()
        ->and($result->factoryPath)->toBeFile()
        ->and($result->seederPath)->toBeFile();

    // Verify Model content
    $modelContent = File::get($result->modelPath);
    expect($modelContent)
        ->toContain('namespace Studeo\\Account\\Models;')
        ->toContain('use Studeo\\Account\\Database\\Factories\\FlightFactory;')
        ->toContain('* @property int $id')
        ->toContain('use Carbon\CarbonImmutable;')
        ->toContain('* @property ?CarbonImmutable $created_at')
        ->toContain('* @property ?CarbonImmutable $updated_at')
        ->toContain('class Flight extends Model')
        ->toContain("protected \$table = 'flights';")
        ->toContain('protected $fillable = [];')
        ->toContain('protected static function newFactory(): FlightFactory')
        ->toContain('return FlightFactory::new();');

    // Verify Migration content
    $migrationContent = File::get($result->migrationPath);
    expect($migrationContent)
        ->toContain("Schema::create('flights', function (Blueprint \$table) {")
        ->toContain("Schema::dropIfExists('flights');");

    // Verify Factory content
    $factoryContent = File::get($result->factoryPath);
    expect($factoryContent)
        ->toContain('namespace Studeo\\Account\\Database\\Factories;')
        ->toContain('use Studeo\\Account\\Models\\Flight;')
        ->toContain('class FlightFactory extends Factory')
        ->toContain('protected $model = Flight::class;');

    // Verify Seeder content
    $seederContent = File::get($result->seederPath);
    expect($seederContent)
        ->toContain('namespace Studeo\\Account\\Database\\Seeders;')
        ->toContain('use Studeo\\Account\\Models\\Flight;')
        ->toContain('class FlightSeeder extends Seeder')
        ->toContain('Flight::factory()->count(10)->create();')
        ->not->toContain('//');
});

test('it respects opt-out options for migration, factory, and seeder', function () {
    $action = app(CreateModelAction::class);

    $options = new ModelGenerationOptions(
        withMigration: false,
        withFactory: false,
        withSeeder: false,
    );

    $result = $action->execute($this->accountModule, 'Airport', $options);

    expect($result->modelPath)->toBeFile()
        ->and($result->migrationPath)->toBeNull()
        ->and($result->factoryPath)->toBeNull()
        ->and($result->seederPath)->toBeNull();

    expect(File::files($this->accountModule->path.'/database/migrations'))->toBeEmpty()
        ->and(File::files($this->accountModule->path.'/database/factories'))->toBeEmpty()
        ->and(File::files($this->accountModule->path.'/database/seeders'))->toBeEmpty();
});

test('it handles compound model names and table pluralization', function () {
    $action = app(CreateModelAction::class);

    $result = $action->execute($this->accountModule, 'UserProfile');

    $modelContent = File::get($result->modelPath);
    expect($modelContent)
        ->toContain('class UserProfile extends Model')
        ->toContain("protected \$table = 'user_profiles';")
        ->toContain('protected static function newFactory(): UserProfileFactory');

    $migrationContent = File::get($result->migrationPath);
    expect($migrationContent)
        ->toContain("Schema::create('user_profiles', function (Blueprint \$table) {");
});

test('it throws when module directory does not exist on disk', function () {
    $nonExistentModule = new ModuleDefinition(
        name: 'Ghost',
        namespace: 'Studeo\\Ghost',
        path: $this->rootModulesPath.'/Ghost',
    );

    $action = app(CreateModelAction::class);

    expect(fn () => $action->execute($nonExistentModule, 'Flight'))
        ->toThrow(
            RuntimeException::class,
            "Module directory does not exist [{$nonExistentModule->path}]."
        );
});

test('it throws when model already exists in the module', function () {
    $action = app(CreateModelAction::class);

    $action->execute($this->accountModule, 'Flight');

    expect(fn () => $action->execute($this->accountModule, 'Flight'))
        ->toThrow(
            RuntimeException::class,
            'Model [Flight] already exists in module [Account].'
        );
});

test('it trims whitespace and studlies the model name', function () {
    $action = app(CreateModelAction::class);

    $result = $action->execute($this->accountModule, ' flight ');

    expect($result->modelPath)->toBe($this->accountModule->path.'/Models/Flight.php');
});

test('it refuses a model name with a path in it instead of flattening it', function (string $name) {
    $action = app(CreateModelAction::class);

    expect(fn () => $action->execute($this->accountModule, $name))
        ->toThrow(RuntimeException::class, 'models, factories, seeders and tables are flat')
        ->and(File::glob($this->accountModule->path.'/Models/*.php'))->toBe([]);
})->with([' /flight/ ', '///', 'Admin/Role', 'Admin\\Role']);

test('it throws when model name is invalid or empty', function () {
    $action = app(CreateModelAction::class);

    expect(fn () => $action->execute($this->accountModule, '   '))
        ->toThrow(
            RuntimeException::class,
            'Invalid model name [].'
        );
});
