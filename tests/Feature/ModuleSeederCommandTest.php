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

it('creates a seeder with name argument successfully', function () {
    $this->artisan('module:seeder', [
        'name' => 'DatabaseSeeder',
        '--module' => 'Account',
    ])
        ->expectsOutput('Seeder [DatabaseSeeder] created successfully in module [Account].')
        ->assertSuccessful();

    $path = $this->accountModule->path.'/database/seeders/DatabaseSeeder.php';
    expect($path)->toBeFile();

    $content = File::get($path);
    expect($content)->toContain('class DatabaseSeeder extends Seeder');
});

it('creates a seeder with explicit --model option, empty while the model has no factory', function () {
    $this->artisan('module:seeder', [
        'name' => 'FlightSeeder',
        '--model' => 'Flight',
        '--module' => 'Account',
    ])
        ->expectsOutput('Seeder [FlightSeeder] created successfully in module [Account].')
        ->assertSuccessful();

    $path = $this->accountModule->path.'/database/seeders/FlightSeeder.php';
    expect($path)->toBeFile();

    $content = File::get($path);
    expect($content)
        ->toContain('class FlightSeeder extends Seeder')
        ->toContain('public function run(): void {}')
        ->not->toContain('use Studeo\Account\Models\Flight;');
});

it('prompts for seeder name when omitted', function () {
    $this->artisan('module:seeder', [
        '--module' => 'Account',
    ])
        ->expectsQuestion('What should the seeder be named?', 'Passenger')
        ->expectsOutput('Seeder [PassengerSeeder] created successfully in module [Account].')
        ->assertSuccessful();

    expect($this->accountModule->path.'/database/seeders/PassengerSeeder.php')->toBeFile();
});

it('prompts for module when --module is omitted', function () {
    $this->writeModuleManifest($this->rootModulesPath.'/Zeta', 'Zeta', 'Studeo\\Zeta');

    $this->artisan('module:seeder', [
        'name' => 'Ticket',
    ])
        ->expectsChoice('Which module should this belong to?', 'Account', ['Account', 'Zeta'])
        ->expectsOutput('Seeder [TicketSeeder] created successfully in module [Account].')
        ->assertSuccessful();

    expect($this->accountModule->path.'/database/seeders/TicketSeeder.php')->toBeFile();
});

it('fails when module does not exist', function () {
    $this->artisan('module:seeder', [
        'name' => 'Ghost',
        '--module' => 'NonExistent',
    ])
        ->expectsOutputToContain('does not exist')
        ->assertFailed();
});
