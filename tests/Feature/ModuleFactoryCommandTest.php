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

it('creates a factory with name argument successfully', function () {
    $this->artisan('module:factory', [
        'name' => 'Flight',
        '--module' => 'Account',
    ])
        ->expectsOutput('Factory [FlightFactory] created successfully in module [Account].')
        ->assertSuccessful();

    $path = $this->accountModule->path.'/database/factories/FlightFactory.php';
    expect($path)->toBeFile();

    $content = File::get($path);
    expect($content)->toContain('class FlightFactory extends Factory');
});

it('creates a factory with explicit --model option', function () {
    $this->artisan('module:factory', [
        'name' => 'AdminUserFactory',
        '--model' => 'User',
        '--module' => 'Account',
    ])
        ->expectsOutput('Factory [AdminUserFactory] created successfully in module [Account].')
        ->assertSuccessful();

    $path = $this->accountModule->path.'/database/factories/AdminUserFactory.php';
    expect($path)->toBeFile();

    $content = File::get($path);
    expect($content)
        ->toContain('class AdminUserFactory extends Factory')
        ->toContain('use Studeo\Account\Models\User;')
        ->toContain('protected $model = User::class;');
});

it('prompts for factory name when omitted', function () {
    $this->artisan('module:factory', [
        '--module' => 'Account',
    ])
        ->expectsQuestion('What should the factory be named?', 'Passenger')
        ->expectsOutput('Factory [PassengerFactory] created successfully in module [Account].')
        ->assertSuccessful();

    expect($this->accountModule->path.'/database/factories/PassengerFactory.php')->toBeFile();
});

it('prompts for module when --module is omitted', function () {
    $this->writeModuleManifest($this->rootModulesPath.'/Zeta', 'Zeta', 'Studeo\\Zeta');

    $this->artisan('module:factory', [
        'name' => 'Ticket',
    ])
        ->expectsChoice('Which module should this belong to?', 'Account', ['Account', 'Zeta'])
        ->expectsOutput('Factory [TicketFactory] created successfully in module [Account].')
        ->assertSuccessful();

    expect($this->accountModule->path.'/database/factories/TicketFactory.php')->toBeFile();
});

it('fails when module does not exist', function () {
    $this->artisan('module:factory', [
        'name' => 'Ghost',
        '--module' => 'NonExistent',
    ])
        ->expectsOutputToContain('does not exist')
        ->assertFailed();
});
