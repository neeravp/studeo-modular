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

it('creates pivot model and migration with two separate arguments', function () {
    $this->artisan('module:pivot', [
        'model1' => 'Flight',
        'model2' => 'Passenger',
        '--module' => 'Account',
    ])
        ->expectsOutput('Pivot [FlightPassenger] created successfully in module [Account].')
        ->assertSuccessful();

    expect($this->accountModule->path.'/Models/FlightPassenger.php')->toBeFile();

    $migrations = File::files($this->accountModule->path.'/database/migrations');
    expect(count($migrations))->toBe(1);
    expect($migrations[0]->getFilename())->toContain('_create_flight_passenger_table.php');
});

it('creates pivot model and migration with single compound argument', function () {
    $this->artisan('module:pivot', [
        'model1' => 'FlightPassenger',
        '--module' => 'Account',
    ])
        ->expectsOutput('Pivot [FlightPassenger] created successfully in module [Account].')
        ->assertSuccessful();

    expect($this->accountModule->path.'/Models/FlightPassenger.php')->toBeFile();
});

it('normalizes reversed model names alphabetically', function () {
    $this->artisan('module:pivot', [
        'model1' => 'Passenger',
        'model2' => 'Flight',
        '--module' => 'Account',
    ])
        ->expectsOutput('Pivot [FlightPassenger] created successfully in module [Account].')
        ->assertSuccessful();

    expect($this->accountModule->path.'/Models/FlightPassenger.php')->toBeFile();
});

it('respects --no-migration flag', function () {
    $this->artisan('module:pivot', [
        'model1' => 'Flight',
        'model2' => 'Passenger',
        '--module' => 'Account',
        '--no-migration' => true,
    ])
        ->expectsOutput('Pivot [FlightPassenger] created successfully in module [Account].')
        ->assertSuccessful();

    expect($this->accountModule->path.'/Models/FlightPassenger.php')->toBeFile();

    $migrations = File::files($this->accountModule->path.'/database/migrations');
    expect(count($migrations))->toBe(0);
});

it('respects --table option', function () {
    $this->artisan('module:pivot', [
        'model1' => 'Flight',
        'model2' => 'Passenger',
        '--module' => 'Account',
        '--table' => 'custom_pivot_table',
    ])
        ->expectsOutput('Pivot [FlightPassenger] created successfully in module [Account].')
        ->assertSuccessful();

    $modelContent = File::get($this->accountModule->path.'/Models/FlightPassenger.php');
    expect($modelContent)->toContain("protected \$table = 'custom_pivot_table';");
});

it('respects --morph flag', function () {
    $this->artisan('module:pivot', [
        'model1' => 'Post',
        'model2' => 'Tag',
        '--module' => 'Account',
        '--morph' => true,
    ])
        ->expectsOutput('Pivot [PostTag] created successfully in module [Account].')
        ->assertSuccessful();

    $modelContent = File::get($this->accountModule->path.'/Models/PostTag.php');
    expect($modelContent)->toContain('extends MorphPivot');
});

it('prompts using multiselect when module has 2 or more models', function () {
    File::put($this->accountModule->path.'/Models/Flight.php', "<?php\nnamespace Studeo\\Account\\Models;\nclass Flight {}");
    File::put($this->accountModule->path.'/Models/Passenger.php', "<?php\nnamespace Studeo\\Account\\Models;\nclass Passenger {}");

    $this->artisan('module:pivot', [
        '--module' => 'Account',
    ])
        ->expectsQuestion('Select the 2 models for this pivot relationship:', ['Flight', 'Passenger'])
        ->expectsOutput('Pivot [FlightPassenger] created successfully in module [Account].')
        ->assertSuccessful();

    expect($this->accountModule->path.'/Models/FlightPassenger.php')->toBeFile();
});

it('prompts using text when module has fewer than 2 models', function () {
    $this->artisan('module:pivot', [
        '--module' => 'Account',
    ])
        ->expectsQuestion('What is the first model?', 'Flight')
        ->expectsQuestion('What is the second model?', 'Passenger')
        ->expectsOutput('Pivot [FlightPassenger] created successfully in module [Account].')
        ->assertSuccessful();

    expect($this->accountModule->path.'/Models/FlightPassenger.php')->toBeFile();
});

it('prompts for module name when --module is omitted', function () {
    $this->writeModuleManifest($this->rootModulesPath.'/Zeta', 'Zeta', 'Studeo\\Zeta');

    $this->artisan('module:pivot', [
        'model1' => 'FlightPassenger',
    ])
        ->expectsChoice('Which module should this belong to?', 'Account', ['Account', 'Zeta'])
        ->expectsOutput('Pivot [FlightPassenger] created successfully in module [Account].')
        ->assertSuccessful();

    expect($this->accountModule->path.'/Models/FlightPassenger.php')->toBeFile();
});

it('fails when module does not exist on disk', function () {
    $this->artisan('module:pivot', [
        'model1' => 'FlightPassenger',
        '--module' => 'Ghost',
    ])
        ->expectsOutput("Module directory does not exist [{$this->rootModulesPath}/Ghost].")
        ->assertFailed();
});

it('fails when pivot model already exists in the module', function () {
    $this->artisan('module:pivot', [
        'model1' => 'Flight',
        'model2' => 'Passenger',
        '--module' => 'Account',
    ])->assertSuccessful();

    $this->artisan('module:pivot', [
        'model1' => 'Flight',
        'model2' => 'Passenger',
        '--module' => 'Account',
    ])
        ->expectsOutput('Pivot model [FlightPassenger] already exists in module [Account].')
        ->assertFailed();
});
