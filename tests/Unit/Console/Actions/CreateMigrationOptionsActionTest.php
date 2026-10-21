<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateMigrationAction;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\Console\Actions\ParseSchemaAction;
use Studeo\Modular\DataObjects\MigrationGenerationOptions;
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

test('it creates a create table migration when --create option is explicitly provided', function () {
    $action = app(CreateMigrationAction::class);

    $result = $action->execute(
        $this->accountModule,
        'custom_migration_name',
        new MigrationGenerationOptions(create: 'custom_table')
    );

    expect($result->type)->toBe(MigrationType::Create)
        ->and($result->tableName)->toBe('custom_table')
        ->and(File::exists($result->path))->toBeTrue();

    $content = File::get($result->path);
    expect($content)->toContain("Schema::create('custom_table', function (Blueprint \$table) {");
});

test('it creates a table update migration when --table option is explicitly provided', function () {
    $action = app(CreateMigrationAction::class);

    $result = $action->execute(
        $this->accountModule,
        'custom_alter_migration',
        new MigrationGenerationOptions(table: 'existing_table')
    );

    expect($result->type)->toBe(MigrationType::Update)
        ->and($result->tableName)->toBe('existing_table')
        ->and(File::exists($result->path))->toBeTrue();

    $content = File::get($result->path);
    expect($content)->toContain("Schema::table('existing_table', function (Blueprint \$table) {");
});

test('it creates a blank migration when --blank option is true', function () {
    $action = app(CreateMigrationAction::class);

    $result = $action->execute(
        $this->accountModule,
        'create_ignored_table',
        new MigrationGenerationOptions(blank: true)
    );

    expect($result->type)->toBe(MigrationType::Blank)
        ->and($result->tableName)->toBeNull();

    $content = File::get($result->path);
    expect($content)
        ->not->toContain('Schema::create')
        ->not->toContain('Schema::table');
});

test('it creates a standalone pivot table migration when --pivot option is provided', function () {
    $action = app(CreateMigrationAction::class);

    $result = $action->execute(
        $this->accountModule,
        'create_flight_passenger_table',
        new MigrationGenerationOptions(pivot: true)
    );

    expect($result->type)->toBe(MigrationType::Pivot)
        ->and($result->tableName)->toBe('flight_passenger')
        ->and(File::exists($result->path))->toBeTrue();

    $content = File::get($result->path);
    expect($content)
        ->toContain("Schema::create('flight_passenger', function (Blueprint \$table) {")
        ->toContain("\$table->foreignId('flight_id')->constrained()->cascadeOnDelete();")
        ->toContain("\$table->foreignId('passenger_id')->constrained()->cascadeOnDelete();")
        ->toContain("\$table->unique(['flight_id', 'passenger_id']);");
});

test('it creates a polymorphic pivot table migration when --pivot and --morph are provided', function () {
    $action = app(CreateMigrationAction::class);

    $result = $action->execute(
        $this->accountModule,
        'create_post_tag_table',
        new MigrationGenerationOptions(pivot: true, morph: true)
    );

    expect($result->type)->toBe(MigrationType::Pivot)
        ->and($result->tableName)->toBe('post_tag')
        ->and(File::exists($result->path))->toBeTrue();

    $content = File::get($result->path);
    expect($content)
        ->toContain("Schema::create('post_tag', function (Blueprint \$table) {")
        ->toContain("\$table->foreignId('post_id')->constrained()->cascadeOnDelete();")
        ->toContain("\$table->morphs('tag');")
        ->toContain("\$table->unique(['post_id', 'tag_id', 'tag_type']);");
});

test('it supports custom foreign keys in pivot migration', function () {
    $action = app(CreateMigrationAction::class);

    $result = $action->execute(
        $this->accountModule,
        'flight_passenger',
        new MigrationGenerationOptions(
            pivot: true,
            foreignKey1: 'plane_id',
            foreignKey2: 'traveler_id'
        )
    );

    $content = File::get($result->path);
    expect($content)
        ->toContain("\$table->foreignId('plane_id')->constrained()->cascadeOnDelete();")
        ->toContain("\$table->foreignId('traveler_id')->constrained()->cascadeOnDelete();")
        ->toContain("\$table->unique(['plane_id', 'traveler_id']);");
});

test('it throws an exception on invalid pivot table name without at least two entities', function () {
    $action = app(CreateMigrationAction::class);

    expect(fn () => $action->execute(
        $this->accountModule,
        'singleentity',
        new MigrationGenerationOptions(pivot: true)
    ))->toThrow(RuntimeException::class, 'Pivot table name [singleentity] must contain at least two parts (e.g. flight_passenger).');
});

test('it populates create migration columns from schema and explicit columns', function () {
    $action = app(CreateMigrationAction::class);
    $schemaParser = app(ParseSchemaAction::class);
    $schema = $schemaParser->execute('title:string, is_active:boolean');

    $result = $action->execute(
        $this->accountModule,
        'create_articles_table',
        new MigrationGenerationOptions(
            schema: $schema,
            columns: ["\$table->text('content')->nullable();"],
        )
    );

    $content = File::get($result->path);
    expect($content)
        ->toContain("Schema::create('articles', function (Blueprint \$table) {")
        ->toContain("\$table->string('title');")
        ->toContain("\$table->boolean('is_active');")
        ->toContain("\$table->text('content')->nullable();");
});
