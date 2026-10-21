<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\Console\Actions\ResolveModelNameAction;
use Studeo\Modular\Console\ModuleModelCommand;
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

it('creates a model with migration, factory, and seeder by default', function () {
    $this->artisan('module:model', [
        'name' => 'Flight',
        '--module' => 'Account',
    ])
        ->expectsOutput('Model [Flight] created successfully in module [Account].')
        ->assertSuccessful();

    $modulePath = $this->accountModule->path;

    expect($modulePath.'/Models/Flight.php')->toBeFile();

    $migrations = File::files($modulePath.'/database/migrations');
    expect($migrations)->toHaveCount(1)
        ->and($migrations[0]->getFilename())->toContain('_create_flights_table.php');

    expect($modulePath.'/database/factories/FlightFactory.php')->toBeFile()
        ->and($modulePath.'/database/seeders/FlightSeeder.php')->toBeFile();
});

it('respects opt-out flags for migration, factory, and seeder', function () {
    $this->artisan('module:model', [
        'name' => 'Airline',
        '--module' => 'Account',
        '--no-migration' => true,
        '--no-factory' => true,
        '--no-seeder' => true,
    ])
        ->expectsOutput('Model [Airline] created successfully in module [Account].')
        ->assertSuccessful();

    $modulePath = $this->accountModule->path;

    expect($modulePath.'/Models/Airline.php')->toBeFile()
        ->and(File::files($modulePath.'/database/migrations'))->toBeEmpty()
        ->and(File::files($modulePath.'/database/factories'))->toBeEmpty()
        ->and(File::files($modulePath.'/database/seeders'))->toBeEmpty();
});

it('prompts for model name when not provided as an argument', function () {
    $this->artisan('module:model', [
        '--module' => 'Account',
    ])
        ->expectsQuestion('What is the name of the model?', 'Booking')
        ->expectsOutput('Model [Booking] created successfully in module [Account].')
        ->assertSuccessful();

    expect($this->accountModule->path.'/Models/Booking.php')->toBeFile();
});

it('prompts for module name when --module is not provided', function () {
    $this->writeModuleManifest($this->rootModulesPath.'/Zeta', 'Zeta', 'Studeo\\Zeta');

    $this->artisan('module:model', [
        'name' => 'Ticket',
    ])
        ->expectsChoice('Which module should this belong to?', 'Account', ['Account', 'Zeta'])
        ->expectsOutput('Model [Ticket] created successfully in module [Account].')
        ->assertSuccessful();

    expect($this->accountModule->path.'/Models/Ticket.php')->toBeFile();
});

it('fails when module does not exist on disk', function () {
    $this->artisan('module:model', [
        'name' => 'GhostModel',
        '--module' => 'Ghost',
    ])
        ->expectsOutput("Module directory does not exist [{$this->rootModulesPath}/Ghost].")
        ->assertFailed();
});

it('fails when model already exists in the module', function () {
    $this->artisan('module:model', [
        'name' => 'Flight',
        '--module' => 'Account',
    ])->assertSuccessful();

    $this->artisan('module:model', [
        'name' => 'Flight',
        '--module' => 'Account',
    ])
        ->expectsOutputToContain('Model [Flight] already exists in module [Account].')
        ->assertFailed();
});

it('prints the path of the model with the others', function () {
    $modulePath = $this->accountModule->path;

    $this->artisan('module:model', ['name' => 'Flight', '--module' => 'Account'])
        ->expectsOutputToContain("Model: {$modulePath}/Models/Flight.php")
        ->expectsOutputToContain("Factory: {$modulePath}/database/factories/FlightFactory.php")
        ->assertSuccessful();
});

it('declares no shortcuts and no options beyond the three opt-outs, --module and --schema', function () {
    $definition = app(ModuleModelCommand::class)->getDefinition();

    expect(array_keys($definition->getOptions()))->toEqualCanonicalizing(['module', 'no-migration', 'no-factory', 'no-seeder', 'schema']);

    foreach ($definition->getOptions() as $option) {
        expect($option->getShortcut())->toBeNull("--{$option->getName()} has a shortcut");
    }
});

it('describes --schema as a file path, inline text or @name', function () {
    $description = app(ModuleModelCommand::class)->getDefinition()->getOption('schema')->getDescription();

    expect($description)->toContain('@name')->toContain('file path')->toContain('inline')->not->toContain('JSON or YAML schema file');
});

it('gives the prompt the same message as the argument for a nested name', function () {
    $command = app(ModuleModelCommand::class);
    $names = app(ResolveModelNameAction::class);
    $validator = (new ReflectionMethod($command, 'promptValidator'))->invoke($command, $names->execute(...));

    try {
        $names->execute('Admin/Role');
        $expected = null;
    } catch (RuntimeException $exception) {
        $expected = $exception->getMessage();
    }

    expect($expected)->toContain('flat')
        ->and($validator('Admin/Role'))->toBe($expected)
        ->and($validator('Role'))->toBeNull();

    $this->artisan('module:model', ['--module' => 'Account'])
        ->expectsQuestion('What is the name of the model?', 'Admin/Role')
        ->assertFailed();

    expect(File::glob($this->accountModule->path.'/Models/*.php'))->toBe([]);
});

it('fails with a full example when the name is missing and the command is not interactive', function () {
    $exitCode = Artisan::call('module:model', ['--module' => 'Account', '--no-interaction' => true]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('module:model Post --module=Blog');
});

it('refuses a nested name given as the argument', function () {
    expect(Artisan::call('module:model', ['name' => 'Admin/Role', '--module' => 'Account']))->toBe(1)
        ->and(Artisan::output())->toContain('flat')
        ->and($this->accountModule->path.'/Models/AdminRole.php')->not->toBeFile();
});

it('creates model and generates attributes from --schema option', function () {
    $this->artisan('module:model', [
        'name' => 'Passenger',
        '--module' => 'Account',
        '--schema' => 'name:string, email:string:unique, is_vip:boolean:default(false)',
    ])
        ->expectsOutput('Model [Passenger] created successfully in module [Account].')
        ->assertSuccessful();

    $modelContent = File::get($this->accountModule->path.'/Models/Passenger.php');
    expect($modelContent)
        ->toContain('* @property string $name')
        ->toContain('* @property string $email')
        ->toContain('* @property bool $is_vip')
        ->toContain("'name',")
        ->toContain("'email',")
        ->toContain("'is_vip',")
        ->toContain("'is_vip' => 'boolean',");

    $factoryContent = File::get($this->accountModule->path.'/database/factories/PassengerFactory.php');
    expect($factoryContent)
        ->toContain("'name' => fake()->name(),")
        ->toContain("'email' => fake()->unique()->safeEmail(),")
        ->toContain("'is_vip' => fake()->boolean(),");
});
