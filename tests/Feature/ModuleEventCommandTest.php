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

it('creates an event with name argument successfully', function () {
    $this->artisan('module:event', [
        'name' => 'OrderPlaced',
        '--module' => 'Account',
    ])
        ->expectsOutput('Event [OrderPlaced] created successfully in module [Account].')
        ->assertSuccessful();

    $path = $this->accountModule->path.'/Events/OrderPlaced.php';
    expect($path)->toBeFile();

    $content = File::get($path);
    expect($content)->toContain('class OrderPlaced');
});

it('prompts for event name when omitted', function () {
    $this->artisan('module:event', [
        '--module' => 'Account',
    ])
        ->expectsQuestion('What should the event be named?', 'FlightCancelled')
        ->expectsOutput('Event [FlightCancelled] created successfully in module [Account].')
        ->assertSuccessful();

    expect($this->accountModule->path.'/Events/FlightCancelled.php')->toBeFile();
});

it('prompts for module when --module is omitted', function () {
    $this->writeModuleManifest($this->rootModulesPath.'/Zeta', 'Zeta', 'Studeo\\Zeta');

    $this->artisan('module:event', [
        'name' => 'PaymentReceived',
    ])
        ->expectsChoice('Which module should this belong to?', 'Account', ['Account', 'Zeta'])
        ->expectsOutput('Event [PaymentReceived] created successfully in module [Account].')
        ->assertSuccessful();

    expect($this->accountModule->path.'/Events/PaymentReceived.php')->toBeFile();
});

it('fails when module does not exist', function () {
    $this->artisan('module:event', [
        'name' => 'GhostEvent',
        '--module' => 'NonExistent',
    ])
        ->expectsOutputToContain('does not exist')
        ->assertFailed();
});
