<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateMigrationAction;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Enums\MigrationType;
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

test('it creates a table creation migration when name matches create_*_table', function () {
    $action = app(CreateMigrationAction::class);

    $result = $action->execute($this->accountModule, 'create_flights_table');

    expect($result->type)->toBe(MigrationType::Create)
        ->and($result->tableName)->toBe('flights')
        ->and($result->filename)->toContain('_create_flights_table.php')
        ->and(File::exists($result->path))->toBeTrue();

    $content = File::get($result->path);
    expect($content)
        ->toContain("Schema::create('flights', function (Blueprint \$table) {")
        ->toContain("Schema::dropIfExists('flights');");
});

test('it creates a table update migration when name matches add_*_to_*_table', function () {
    $action = app(CreateMigrationAction::class);

    $result = $action->execute($this->accountModule, 'add_status_to_orders_table');

    expect($result->type)->toBe(MigrationType::Update)
        ->and($result->tableName)->toBe('orders')
        ->and($result->filename)->toContain('_add_status_to_orders_table.php')
        ->and(File::exists($result->path))->toBeTrue();

    $content = File::get($result->path);
    expect($content)
        ->toContain("Schema::table('orders', function (Blueprint \$table) {")
        ->not->toContain('Schema::create');
});

test('it creates a table update migration when name matches drop_*_from_*_table', function () {
    $action = app(CreateMigrationAction::class);

    $result = $action->execute($this->accountModule, 'drop_token_from_users_table');

    expect($result->type)->toBe(MigrationType::Update)
        ->and($result->tableName)->toBe('users')
        ->and($result->filename)->toContain('_drop_token_from_users_table.php')
        ->and(File::exists($result->path))->toBeTrue();

    $content = File::get($result->path);
    expect($content)
        ->toContain("Schema::table('users', function (Blueprint \$table) {");
});

test('it creates a blank migration when name has no matching pattern', function () {
    $action = app(CreateMigrationAction::class);

    $result = $action->execute($this->accountModule, 'backfill_slugs_data_batch');

    expect($result->type)->toBe(MigrationType::Blank)
        ->and($result->tableName)->toBeNull()
        ->and($result->filename)->toContain('_backfill_slugs_data_batch.php')
        ->and(File::exists($result->path))->toBeTrue();

    $content = File::get($result->path);
    expect($content)
        ->toContain('public function up(): void')
        ->not->toContain('Schema::create')
        ->not->toContain('Schema::table');
});

test('it strips .php extension and sanitizes migration name', function () {
    $action = app(CreateMigrationAction::class);

    $result = $action->execute($this->accountModule, 'CreateFlightsTable.php');

    expect($result->filename)->toContain('_create_flights_table.php')
        ->not->toContain('.php.php');
});

test('it throws an exception when module directory does not exist', function () {
    $action = app(CreateMigrationAction::class);

    $ghostModule = new ModuleDefinition(
        name: 'Ghost',
        namespace: 'Studeo\\Ghost',
        path: $this->rootModulesPath.'/Ghost',
    );

    expect(fn () => $action->execute($ghostModule, 'create_users_table'))
        ->toThrow(RuntimeException::class, "Module directory does not exist [{$ghostModule->path}].");
});

test('it throws an exception on invalid or empty migration name', function () {
    $action = app(CreateMigrationAction::class);

    expect(fn () => $action->execute($this->accountModule, '   ///   '))
        ->toThrow(RuntimeException::class, 'Invalid migration name [   ///   ].');
});
