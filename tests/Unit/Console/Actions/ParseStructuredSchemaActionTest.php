<?php

use Studeo\Modular\Console\Actions\ParseStructuredSchemaAction;

beforeEach(function () {
    $this->action = new ParseStructuredSchemaAction;
});

it('parses structured array with name, table, fields and relationships', function () {
    $data = [
        'name' => 'Article',
        'table' => 'articles',
        'fields' => [
            'title' => [
                'type' => 'string',
                'unique' => true,
            ],
            'status' => [
                'type' => 'enum',
                'enum' => 'PostStatus',
                'default' => 'draft',
            ],
            'user_id' => [
                'type' => 'foreignId',
                'constrained' => true,
                'cascade_on_delete' => true,
            ],
        ],
        'relationships' => [
            'author' => [
                'type' => 'belongsTo',
                'model' => 'User',
            ],
        ],
    ];

    $schema = $this->action->execute($data);

    expect($schema->name)->toBe('Article')
        ->and($schema->table)->toBe('articles')
        ->and($schema->fields)->toHaveCount(3)
        ->and($schema->fields['title']->isUnique())->toBeTrue()
        ->and($schema->fields['status']->enum)->toBe('PostStatus')
        ->and($schema->fields['status']->default->value)->toBe('draft')
        ->and($schema->fields['user_id']->migrationDefinition())->toBe("\$table->foreignId('user_id')->constrained()->cascadeOnDelete();")
        ->and($schema->relationships['author']->relatedModel)->toBe('User');
});

it('parses shorthand string field definitions within structured array', function () {
    $data = [
        'fields' => [
            'slug' => 'string:unique',
            'is_published' => 'boolean:default(false)',
        ],
    ];

    $schema = $this->action->execute($data);

    expect($schema->fields['slug']->isUnique())->toBeTrue()
        ->and($schema->fields['is_published']->type)->toBe('boolean')
        ->and($schema->fields['is_published']->default->value)->toBeFalse();
});

it('parses inline parameterized types in structured config', function () {
    $data = [
        'fields' => [
            'price' => [
                'type' => 'decimal(10,2)',
                'nullable' => true,
            ],
            'iso_code' => [
                'type' => 'char(3)',
            ],
        ],
    ];

    $schema = $this->action->execute($data);

    expect($schema->fields['price']->type)->toBe('decimal')
        ->and($schema->fields['price']->typeArguments)->toBe(['10', '2'])
        ->and($schema->fields['price']->migrationDefinition())->toBe("\$table->decimal('price', 10, 2)->nullable();")
        ->and($schema->fields['iso_code']->type)->toBe('char')
        ->and($schema->fields['iso_code']->typeArguments)->toBe(['3'])
        ->and($schema->fields['iso_code']->migrationDefinition())->toBe("\$table->char('iso_code', 3);");
});

it('parses explicit parameterized attributes in structured config', function () {
    $data = [
        'fields' => [
            'rate' => [
                'type' => 'decimal',
                'precision' => 8,
                'scale' => 4,
            ],
            'postal_code' => [
                'type' => 'string',
                'length' => 10,
            ],
            'coordinates' => [
                'type' => 'decimal',
                'type_arguments' => [12, 6],
            ],
        ],
    ];

    $schema = $this->action->execute($data);

    expect($schema->fields['rate']->typeArguments)->toBe(['8', '4'])
        ->and($schema->fields['rate']->migrationDefinition())->toBe("\$table->decimal('rate', 8, 4);")
        ->and($schema->fields['postal_code']->typeArguments)->toBe(['10'])
        ->and($schema->fields['postal_code']->migrationDefinition())->toBe("\$table->string('postal_code', 10);")
        ->and($schema->fields['coordinates']->typeArguments)->toBe(['12', '6'])
        ->and($schema->fields['coordinates']->migrationDefinition())->toBe("\$table->decimal('coordinates', 12, 6);");
});
