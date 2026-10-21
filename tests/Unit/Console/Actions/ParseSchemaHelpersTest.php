<?php

use Studeo\Modular\Console\Actions\ParseSchemaAction;
use Studeo\Modular\Support\FakerDefinitionResolver;
use Studeo\Modular\Support\MigrationColumnResolver;
use Studeo\Modular\Support\ModelStubContent;
use Studeo\Modular\Support\PhpImports;

beforeEach(function () {
    $this->parser = new ParseSchemaAction;
});

it('correctly generates schema helper outputs', function () {
    $yaml = <<<'YAML'
fields:
  title: string
  is_active: boolean
  published_at: datetime:nullable
  metadata: json:nullable
  user_id: foreignId:constrained:cascadeOnDelete
relationships:
  user:
    type: belongsTo
    model: User
  tags:
    type: belongsToMany
    model: Tag
YAML;

    $schema = $this->parser->execute($yaml);
    $imports = new PhpImports('Studeo\Blog\Models');

    expect(ModelStubContent::fillableFields($schema))->toBe(['title', 'is_active', 'published_at', 'metadata', 'user_id'])
        ->and(ModelStubContent::castMap($schema))->toBe([
            'is_active' => 'boolean',
            'published_at' => 'immutable_datetime',
            'metadata' => 'array',
        ])
        ->and(ModelStubContent::phpDocProperties($schema->forModels(['Tag']), 'Studeo\Blog\Models', $imports))->toContain(
            '@property string $title',
            '@property bool $is_active',
            '@property ?CarbonImmutable $published_at',
            '@property ?array $metadata',
            '@property int $user_id',
            '@property-read ?Authenticatable $user',
            '@property-read Collection<int, Tag> $tags'
        )
        ->and($imports->statements())->toBe("use Carbon\\CarbonImmutable;\nuse Illuminate\\Contracts\\Auth\\Authenticatable;\nuse Illuminate\\Database\\Eloquent\\Collection;")
        ->and(MigrationColumnResolver::columns($schema))->toContain(
            "\$table->string('title');",
            "\$table->boolean('is_active');",
            "\$table->dateTime('published_at')->nullable();",
            "\$table->json('metadata')->nullable();",
            "\$table->foreignId('user_id')->constrained()->cascadeOnDelete();"
        )
        ->and(FakerDefinitionResolver::definition($schema, moduleWithModels([]), 'App\Models\User')->attributes)->toMatchArray([
            'title' => 'fake()->sentence()',
            'is_active' => 'fake()->boolean()',
            'user_id' => "fn () => config('auth.providers.users.model')::factory()",
        ]);
});

it('handles explicit fillable and guarded configurations in yaml and cli', function () {
    $yaml = <<<'YAML'
fields:
  uuid:
    type: string
    guarded: true
  invoice_number:
    type: string
    fillable: false
  title:
    type: string
YAML;

    $schema = $this->parser->execute($yaml);
    expect(ModelStubContent::fillableFields($schema))->toBe(['title'])
        ->and($schema->fields['uuid']->isFillable())->toBeFalse()
        ->and($schema->fields['invoice_number']->isFillable())->toBeFalse()
        ->and($schema->fields['title']->isFillable())->toBeTrue();

    $cliSchema = $this->parser->execute('uuid:string:guarded,invoice_number:string:guarded,title:string');
    expect(ModelStubContent::fillableFields($cliSchema))->toBe(['title'])
        ->and(MigrationColumnResolver::columns($cliSchema))->toContain(
            "\$table->string('uuid');",
            "\$table->string('invoice_number');",
            "\$table->string('title');"
        );
});

it('handles explicit and smart casts including hashed password and datetime formats', function () {
    $yaml = <<<'YAML'
fields:
  password: string
  created_day:
    type: datetime
    cast: 'datetime:Y-m-d'
YAML;

    $schema = $this->parser->execute($yaml);
    expect(ModelStubContent::castMap($schema))->toBe([
        'password' => 'hashed',
        'created_day' => 'datetime:Y-m-d',
    ]);

    $cliSchema = $this->parser->execute('password:string,event_at:datetime:cast(immutable_datetime)');
    expect(ModelStubContent::castMap($cliSchema))->toBe([
        'password' => 'hashed',
        'event_at' => 'immutable_datetime',
    ])
        ->and(MigrationColumnResolver::columns($cliSchema))->not->toContain('cast(');
});
