<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\Console\Actions\CreatePivotAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\PivotGenerationOptions;
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

test('it creates a standard pivot model and migration from two separate models in alphabetical order', function () {
    $action = app(CreatePivotAction::class);

    // Pass Passenger and Flight in reverse order; should be normalized alphabetically to FlightPassenger
    $result = $action->execute($this->accountModule, 'Passenger', 'Flight');

    expect($result->modelName)->toBe('FlightPassenger');
    expect($result->tableName)->toBe('flight_passenger');
    expect($result->modelPath)->toBeFile();
    expect($result->migrationPath)->toBeFile();

    // Verify Pivot Model content
    $modelContent = File::get($result->modelPath);
    expect($modelContent)
        ->toContain('namespace Studeo\\Account\\Models;')
        ->toContain('use Illuminate\\Database\\Eloquent\\Relations\\Pivot;')
        ->toContain('class FlightPassenger extends Pivot')
        ->toContain("protected \$table = 'flight_passenger';")
        ->toContain('public $incrementing = true;');

    // Verify Pivot Migration content
    $migrationContent = File::get($result->migrationPath);
    expect($migrationContent)
        ->toContain("Schema::create('flight_passenger', function (Blueprint \$table) {")
        ->toContain('$table->id();')
        ->toContain("\$table->foreignId('flight_id')->constrained()->cascadeOnDelete();")
        ->toContain("\$table->foreignId('passenger_id')->constrained()->cascadeOnDelete();")
        ->toContain("\$table->unique(['flight_id', 'passenger_id']);")
        ->toContain('$table->timestamps();');
});

test('it creates a pivot from a single compound PascalCase name', function () {
    $action = app(CreatePivotAction::class);

    $result = $action->execute($this->accountModule, 'FlightPassenger');

    expect($result->modelName)->toBe('FlightPassenger');
    expect($result->tableName)->toBe('flight_passenger');
    expect($result->modelPath)->toBeFile();
});

test('it creates a pivot from a reversed compound PascalCase name and normalizes alphabetically', function () {
    $action = app(CreatePivotAction::class);

    $result = $action->execute($this->accountModule, 'PassengerFlight');

    expect($result->modelName)->toBe('FlightPassenger');
    expect($result->tableName)->toBe('flight_passenger');
    expect($result->modelPath)->toBeFile();
});

test('it creates a pivot from a snake_case name', function () {
    $action = app(CreatePivotAction::class);

    $result = $action->execute($this->accountModule, 'passenger_flight');

    expect($result->modelName)->toBe('FlightPassenger');
    expect($result->tableName)->toBe('flight_passenger');
    expect($result->modelPath)->toBeFile();
});

test('it resolves compound model names matching existing module models', function () {
    // Create UserProfile model in module
    File::put($this->accountModule->path.'/Models/UserProfile.php', "<?php\nnamespace Studeo\\Account\\Models;\nclass UserProfile {}");
    File::put($this->accountModule->path.'/Models/Role.php', "<?php\nnamespace Studeo\\Account\\Models;\nclass Role {}");

    $action = app(CreatePivotAction::class);

    $result = $action->execute($this->accountModule, 'UserProfileRole');

    // Role (R) comes before UserProfile (U)
    expect($result->modelName)->toBe('RoleUserProfile');
    expect($result->tableName)->toBe('role_user_profile');
    expect($result->modelPath)->toBeFile();

    $migrationContent = File::get($result->migrationPath);
    expect($migrationContent)
        ->toContain("\$table->foreignId('role_id')->constrained()->cascadeOnDelete();")
        ->toContain("\$table->foreignId('user_profile_id')->constrained()->cascadeOnDelete();");
});

test('it respects withMigration false option', function () {
    $action = app(CreatePivotAction::class);

    $result = $action->execute(
        $this->accountModule,
        'Flight',
        'Passenger',
        new PivotGenerationOptions(withMigration: false)
    );

    expect($result->modelPath)->toBeFile();
    expect($result->migrationPath)->toBeNull();
});

test('it respects custom table name option', function () {
    $action = app(CreatePivotAction::class);

    $result = $action->execute(
        $this->accountModule,
        'Flight',
        'Passenger',
        new PivotGenerationOptions(table: 'custom_flight_passengers')
    );

    expect($result->tableName)->toBe('custom_flight_passengers');

    $modelContent = File::get($result->modelPath);
    expect($modelContent)->toContain("protected \$table = 'custom_flight_passengers';");

    $migrationContent = File::get($result->migrationPath);
    expect($migrationContent)->toContain("Schema::create('custom_flight_passengers', function (Blueprint \$table) {");
});

test('it creates a morph pivot model and polymorphic migration when morph is true', function () {
    $action = app(CreatePivotAction::class);

    $result = $action->execute(
        $this->accountModule,
        'Tag',
        'Post',
        new PivotGenerationOptions(morph: true)
    );

    expect($result->modelPath)->toBeFile();

    $modelContent = File::get($result->modelPath);
    expect($modelContent)
        ->toContain('use Illuminate\\Database\\Eloquent\\Relations\\MorphPivot;')
        ->toContain('class PostTag extends MorphPivot');

    $migrationContent = File::get($result->migrationPath);
    expect($migrationContent)
        ->toContain("\$table->morphs('tag');");
});

test('it throws an exception if the module directory does not exist', function () {
    $missingModule = new ModuleDefinition(
        name: 'Missing',
        namespace: 'Studeo\\Missing',
        path: $this->rootModulesPath.'/Missing',
    );

    $action = app(CreatePivotAction::class);

    expect(fn () => $action->execute($missingModule, 'Flight', 'Passenger'))
        ->toThrow(RuntimeException::class, "Module directory does not exist [{$missingModule->path}].");
});

test('it throws an exception if the pivot model already exists in module', function () {
    $action = app(CreatePivotAction::class);

    $action->execute($this->accountModule, 'Flight', 'Passenger');

    expect(fn () => $action->execute($this->accountModule, 'Flight', 'Passenger'))
        ->toThrow(RuntimeException::class, 'Pivot model [FlightPassenger] already exists in module [Account].');
});

test('it throws an exception on invalid or unparseable pivot name', function () {
    $action = app(CreatePivotAction::class);

    expect(fn () => $action->execute($this->accountModule, 'Singleword'))
        ->toThrow(RuntimeException::class, 'Cannot parse two entities from pivot name [Singleword].');
});
