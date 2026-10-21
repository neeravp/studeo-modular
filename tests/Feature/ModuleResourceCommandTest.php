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

it('creates a single JsonResource in the module', function () {
    $this->artisan('module:resource', [
        'name' => 'FlightResource',
        '--module' => 'Account',
    ])
        ->expectsOutput('Resource [FlightResource] created successfully in module [Account].')
        ->assertSuccessful();

    $resourcePath = $this->accountModule->path.'/ApiResources/FlightResource.php';
    expect($resourcePath)->toBeFile();

    $content = File::get($resourcePath);
    expect($content)
        ->toContain('namespace Studeo\\Account\\ApiResources;')
        ->toContain('class FlightResource extends JsonResource');
});

it('creates a ResourceCollection when --collection flag is used', function () {
    $this->artisan('module:resource', [
        'name' => 'Flight',
        '--module' => 'Account',
        '--collection' => true,
    ])
        ->expectsOutput('Resource [FlightCollection] created successfully in module [Account].')
        ->assertSuccessful();

    $resourcePath = $this->accountModule->path.'/ApiResources/FlightCollection.php';
    expect($resourcePath)->toBeFile();

    $content = File::get($resourcePath);
    expect($content)
        ->toContain('class FlightCollection extends ResourceCollection');
});

it('automatically appends Resource suffix if omitted', function () {
    $this->artisan('module:resource', [
        'name' => 'Passenger',
        '--module' => 'Account',
    ])
        ->expectsOutput('Resource [PassengerResource] created successfully in module [Account].')
        ->assertSuccessful();

    expect($this->accountModule->path.'/ApiResources/PassengerResource.php')->toBeFile();
});

it('prompts for resource name when argument is omitted', function () {
    $this->artisan('module:resource', [
        '--module' => 'Account',
    ])
        ->expectsQuestion('What is the name of the resource?', 'BookingResource')
        ->expectsOutput('Resource [BookingResource] created successfully in module [Account].')
        ->assertSuccessful();

    expect($this->accountModule->path.'/ApiResources/BookingResource.php')->toBeFile();
});

it('prompts for module name when --module is omitted', function () {
    $this->writeModuleManifest($this->rootModulesPath.'/Zeta', 'Zeta', 'Studeo\\Zeta');

    $this->artisan('module:resource', [
        'name' => 'TicketResource',
    ])
        ->expectsChoice('Which module should this belong to?', 'Account', ['Account', 'Zeta'])
        ->expectsOutput('Resource [TicketResource] created successfully in module [Account].')
        ->assertSuccessful();

    expect($this->accountModule->path.'/ApiResources/TicketResource.php')->toBeFile();
});

it('fails when module does not exist on disk', function () {
    $this->artisan('module:resource', [
        'name' => 'GhostResource',
        '--module' => 'Ghost',
    ])
        ->expectsOutput("Module directory does not exist [{$this->rootModulesPath}/Ghost].")
        ->assertFailed();
});

it('fails when resource already exists in the module', function () {
    $this->artisan('module:resource', [
        'name' => 'FlightResource',
        '--module' => 'Account',
    ])->assertSuccessful();

    $this->artisan('module:resource', [
        'name' => 'FlightResource',
        '--module' => 'Account',
    ])
        ->expectsOutput('Resource [FlightResource] already exists in module [Account].')
        ->assertFailed();
});
