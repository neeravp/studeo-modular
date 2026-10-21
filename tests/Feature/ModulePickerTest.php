<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

const PICKER_QUESTION = 'Which module should this belong to?';

beforeEach(function () {
    $this->rootPath = config('modular.root_path');
    File::deleteDirectory($this->rootPath);
    File::ensureDirectoryExists($this->rootPath);
});

afterEach(function () {
    File::deleteDirectory($this->rootPath);
});

function pickerModule(object $test, string $name): void
{
    $test->writeModuleManifest($test->rootPath.'/'.$name, $name, 'Studeo\\'.$name);
}

it('fails with the module:make hint when there are no modules', function () {
    $this->artisan('module:event', ['name' => 'OrderPlaced'])
        ->expectsOutputToContain('No modules found. Create one with [php artisan module:make')
        ->assertFailed();
});

it('names the skipped directories when there are no modules', function () {
    File::ensureDirectoryExists($this->rootPath.'/Legacy');
    File::put($this->rootPath.'/Legacy/composer.json', '{}');

    $this->artisan('module:event', ['name' => 'OrderPlaced'])
        ->expectsOutputToContain('Skipped [Legacy]: legacy composer.json found')
        ->assertFailed();
});

it('uses the only module and says which', function () {
    pickerModule($this, 'Account');

    $this->artisan('module:event', ['name' => 'OrderPlaced'])
        ->expectsOutput('Using module [Account].')
        ->expectsOutputToContain('created successfully in module [Account]')
        ->assertSuccessful();

    expect($this->rootPath.'/Account/Events/OrderPlaced.php')->toBeFile();
});

it('asks with a select when there are several modules', function () {
    pickerModule($this, 'Account');
    pickerModule($this, 'Billing');

    $this->artisan('module:event', ['name' => 'OrderPlaced'])
        ->expectsChoice(PICKER_QUESTION, 'Billing', ['Account', 'Billing'])
        ->expectsOutputToContain('created successfully in module [Billing]')
        ->assertSuccessful();

    expect($this->rootPath.'/Billing/Events/OrderPlaced.php')->toBeFile();
});

it('never offers a skipped directory', function () {
    pickerModule($this, 'Account');
    pickerModule($this, 'Billing');
    $this->writeRawModuleManifest($this->rootPath.'/Broken', '{not json');
    File::ensureDirectoryExists($this->rootPath.'/Legacy');
    File::put($this->rootPath.'/Legacy/composer.json', '{}');
    File::ensureDirectoryExists($this->rootPath.'/Empty');

    $this->artisan('module:event', ['name' => 'OrderPlaced'])
        ->expectsChoice(PICKER_QUESTION, 'Account', ['Account', 'Billing'])
        ->assertSuccessful();
});

it('lets --module win over the picker', function () {
    pickerModule($this, 'Account');
    pickerModule($this, 'Billing');

    $this->artisan('module:event', ['name' => 'OrderPlaced', '--module' => 'Billing'])
        ->doesntExpectOutput('Using module [Billing].')
        ->expectsOutputToContain('created successfully in module [Billing]')
        ->assertSuccessful();
});

it('reports the manifest error when --module names a module with an invalid manifest', function () {
    pickerModule($this, 'Account');
    $this->writeRawModuleManifest($this->rootPath.'/Broken', '{not json');

    $this->artisan('module:event', ['name' => 'OrderPlaced', '--module' => 'Broken'])
        ->expectsOutputToContain('Invalid module manifest')
        ->assertFailed();

    expect($this->rootPath.'/Broken/Events')->not->toBeDirectory();
});

it('refuses a blank --module', function () {
    pickerModule($this, 'Account');

    $this->artisan('module:event', ['name' => 'OrderPlaced', '--module' => ''])
        ->expectsOutput('The module name is required.')
        ->assertFailed();
});

it('names --module when there are several modules and the command cannot prompt', function () {
    pickerModule($this, 'Account');
    pickerModule($this, 'Billing');

    $this->artisan('module:event', ['name' => 'OrderPlaced', '--no-interaction' => true])
        ->expectsOutputToContain('(Account, Billing). Pass --module=<name> to choose one.')
        ->assertFailed();

    expect($this->rootPath.'/Account/Events/OrderPlaced.php')->not->toBeFile();
});
