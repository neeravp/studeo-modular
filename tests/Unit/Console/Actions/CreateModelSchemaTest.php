<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModelAction;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\DataObjects\ModelGenerationOptions;
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

test('it populates fillable, casts, properties, relationships, migration columns, and factory attributes from inline schema', function () {
    $action = app(CreateModelAction::class);

    $options = new ModelGenerationOptions(
        withMigration: true,
        withFactory: true,
        withSeeder: false,
        schemaPath: 'title:string, is_active:boolean, published_at:datetime:nullable, user:belongsTo(User)',
    );

    $result = $action->execute($this->accountModule, 'Article', $options);

    $modelContent = File::get($result->modelPath);
    expect($modelContent)
        ->toContain('* @property string $title')
        ->toContain('* @property bool $is_active')
        ->toContain('* @property ?CarbonImmutable $published_at')
        ->toContain('use Illuminate\Contracts\Auth\Authenticatable;')
        ->toContain('* @property-read ?Authenticatable $user')
        ->toContain("'title',")
        ->toContain("'is_active',")
        ->toContain("'published_at',")
        ->toContain("'is_active' => 'boolean',")
        ->toContain("'published_at' => 'immutable_datetime',")
        ->toContain('use Illuminate\Database\Eloquent\Relations\BelongsTo;')
        ->toContain('public function user(): BelongsTo')
        ->toContain("return \$this->belongsTo(config('auth.providers.users.model'));")
        ->not->toContain('App\\')->not->toContain('User;')->not->toContain('User::class');

    $migrationContent = File::get($result->migrationPath);
    expect($migrationContent)
        ->toContain("\$table->string('title');")
        ->toContain("\$table->boolean('is_active');")
        ->toContain("\$table->dateTime('published_at')->nullable();");

    $factoryContent = File::get($result->factoryPath);
    expect($factoryContent)
        ->toContain("'title' => fake()->sentence(),")
        ->toContain("'is_active' => fake()->boolean(),")
        ->toContain("'published_at' => fake()->dateTime(),");
});

test('it populates model, migration, and factory from yaml schema file', function () {
    File::put($this->accountModule->path.'/Models/Customer.php', '<?php');
    $yamlPath = $this->accountModule->path.'/invoice_schema.yaml';
    File::put($yamlPath, <<<'YAML'
table: client_invoices
fields:
  invoice_number:
    type: string
    unique: true
  amount_cents: integer
  is_paid:
    type: boolean
    default: false
relationships:
  customer:
    type: belongsTo
    model: Customer
YAML);

    $action = app(CreateModelAction::class);
    $options = new ModelGenerationOptions(
        schemaPath: $yamlPath,
    );

    $result = $action->execute($this->accountModule, 'Invoice', $options);

    $modelContent = File::get($result->modelPath);
    expect($modelContent)
        ->toContain("protected \$table = 'client_invoices';")
        ->toContain("'invoice_number',")
        ->toContain("'amount_cents',")
        ->toContain("'is_paid',")
        ->toContain("'amount_cents' => 'integer',")
        ->toContain("'is_paid' => 'boolean',")
        ->toContain('use Illuminate\Database\Eloquent\Relations\BelongsTo;')
        ->toContain('public function customer(): BelongsTo');

    $migrationContent = File::get($result->migrationPath);
    expect($migrationContent)
        ->toContain("Schema::create('client_invoices', function (Blueprint \$table) {")
        ->toContain("\$table->string('invoice_number')->unique();")
        ->toContain("\$table->integer('amount_cents');")
        ->toContain("\$table->boolean('is_paid')->default(false);");

    $factoryContent = File::get($result->factoryPath);
    expect($factoryContent)
        ->toContain("'amount_cents' => fake()->randomNumber(),")
        ->toContain("'is_paid' => fake()->boolean(),");
});
