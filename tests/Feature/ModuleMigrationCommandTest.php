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

it('creates a migration with name argument successfully', function () {
    $this->artisan('module:migration', [
        'name' => 'create_flights_table',
        '--module' => 'Account',
    ])
        ->expectsOutputToContain('created successfully in module [Account].')
        ->assertSuccessful();

    $migrations = File::files($this->accountModule->path.'/database/migrations');
    expect(count($migrations))->toBe(1);
    expect($migrations[0]->getFilename())->toContain('_create_flights_table.php');

    $content = File::get($migrations[0]->getPathname());
    expect($content)->toContain("Schema::create('flights', function (Blueprint \$table) {");
});

it('creates an alter table migration with name matching add_*_to_*_table', function () {
    $this->artisan('module:migration', [
        'name' => 'add_status_to_orders_table',
        '--module' => 'Account',
    ])
        ->expectsOutputToContain('created successfully in module [Account].')
        ->assertSuccessful();

    $migrations = File::files($this->accountModule->path.'/database/migrations');
    expect(count($migrations))->toBe(1);

    $content = File::get($migrations[0]->getPathname());
    expect($content)->toContain("Schema::table('orders', function (Blueprint \$table) {");
});

it('creates a migration with explicit --create flag', function () {
    $this->artisan('module:migration', [
        'name' => 'custom_migration',
        '--module' => 'Account',
        '--create' => 'custom_table',
    ])
        ->assertSuccessful();

    $migrations = File::files($this->accountModule->path.'/database/migrations');
    $content = File::get($migrations[0]->getPathname());
    expect($content)->toContain("Schema::create('custom_table', function (Blueprint \$table) {");
});

it('creates a migration with explicit --table flag', function () {
    $this->artisan('module:migration', [
        'name' => 'custom_update',
        '--module' => 'Account',
        '--table' => 'existing_table',
    ])
        ->assertSuccessful();

    $migrations = File::files($this->accountModule->path.'/database/migrations');
    $content = File::get($migrations[0]->getPathname());
    expect($content)->toContain("Schema::table('existing_table', function (Blueprint \$table) {");
});

it('creates a blank migration with explicit --blank flag', function () {
    $this->artisan('module:migration', [
        'name' => 'create_users_table',
        '--module' => 'Account',
        '--blank' => true,
    ])
        ->assertSuccessful();

    $migrations = File::files($this->accountModule->path.'/database/migrations');
    $content = File::get($migrations[0]->getPathname());
    expect($content)
        ->not->toContain('Schema::create')
        ->not->toContain('Schema::table');
});

it('creates a pivot migration with explicit --pivot flag', function () {
    $this->artisan('module:migration', [
        'name' => 'create_flight_passenger_table',
        '--module' => 'Account',
        '--pivot' => true,
    ])
        ->assertSuccessful();

    $migrations = File::files($this->accountModule->path.'/database/migrations');
    $content = File::get($migrations[0]->getPathname());
    expect($content)
        ->toContain("Schema::create('flight_passenger', function (Blueprint \$table) {")
        ->toContain("\$table->foreignId('flight_id')->constrained()->cascadeOnDelete();")
        ->toContain("\$table->foreignId('passenger_id')->constrained()->cascadeOnDelete();");
});

it('creates a polymorphic pivot migration with --pivot and --morph flags', function () {
    $this->artisan('module:migration', [
        'name' => 'create_post_tag_table',
        '--module' => 'Account',
        '--pivot' => true,
        '--morph' => true,
    ])
        ->assertSuccessful();

    $migrations = File::files($this->accountModule->path.'/database/migrations');
    $content = File::get($migrations[0]->getPathname());
    expect($content)
        ->toContain("Schema::create('post_tag', function (Blueprint \$table) {")
        ->toContain("\$table->morphs('tag');");
});

it('prompts for migration name interactively when omitted', function () {
    $this->artisan('module:migration', [
        '--module' => 'Account',
    ])
        ->expectsQuestion('What should the migration be named?', 'create_invoices_table')
        ->assertSuccessful();

    $migrations = File::files($this->accountModule->path.'/database/migrations');
    expect(count($migrations))->toBe(1);
    expect($migrations[0]->getFilename())->toContain('_create_invoices_table.php');
});

it('prompts for module interactively when --module is omitted', function () {
    $this->writeModuleManifest($this->rootModulesPath.'/Zeta', 'Zeta', 'Studeo\\Zeta');

    $this->artisan('module:migration', [
        'name' => 'create_tickets_table',
    ])
        ->expectsChoice('Which module should this belong to?', 'Account', ['Account', 'Zeta'])
        ->assertSuccessful();

    $migrations = File::files($this->accountModule->path.'/database/migrations');
    expect(count($migrations))->toBe(1);
    expect($migrations[0]->getFilename())->toContain('_create_tickets_table.php');
});

it('displays an error when the target module does not exist', function () {
    $this->artisan('module:migration', [
        'name' => 'create_ghosts_table',
        '--module' => 'NonExistentModule',
    ])
        ->expectsOutputToContain('does not exist')
        ->assertFailed();
});
