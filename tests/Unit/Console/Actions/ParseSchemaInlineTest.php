<?php

use Studeo\Modular\Console\Actions\ParseSchemaAction;
use Studeo\Modular\DataObjects\FieldDefinition;
use Studeo\Modular\DataObjects\RelationshipDefinition;

beforeEach(function () {
    $this->parser = new ParseSchemaAction;
});

it('returns empty schema definition when input is null or empty', function () {
    $schema = $this->parser->execute(null);

    expect($schema->isEmpty())->toBeTrue()
        ->and($schema->fields)->toBeEmpty()
        ->and($schema->relationships)->toBeEmpty();

    $schemaBlank = $this->parser->execute('   ');
    expect($schemaBlank->isEmpty())->toBeTrue();
});

it('parses inline comma-separated cli string with fields and modifiers', function () {
    $schema = $this->parser->execute('title:string:unique, body:text:nullable, is_published:boolean:default(false)');

    expect($schema->fields)->toHaveCount(3)
        ->and($schema->fields['title'])->toBeInstanceOf(FieldDefinition::class)
        ->and($schema->fields['title']->name)->toBe('title')
        ->and($schema->fields['title']->type)->toBe('string')
        ->and($schema->fields['title']->isUnique())->toBeTrue()
        ->and($schema->fields['body']->isNullable())->toBeTrue()
        ->and($schema->fields['is_published']->type)->toBe('boolean')
        ->and($schema->fields['is_published']->default)->not->toBeNull()
        ->and($schema->fields['is_published']->default->value)->toBeFalse();
});

it('parses enum fields by class name in inline cli string', function () {
    $schema = $this->parser->execute('status:enum(PostStatus):nullable');

    expect($schema->fields)->toHaveKey('status');
    $field = $schema->fields['status'];
    expect($field->type)->toBe('enum')
        ->and($field->enum)->toBe('PostStatus')
        ->and($field->migrationDefinition())->toBe("\$table->string('status', 30)->index()->nullable();");
});

it('rejects the old inline case list in an inline cli string', function () {
    $this->parser->execute('status:enum(draft,published,archived):default(draft)');
})->throws(InvalidArgumentException::class, 'module:enum PostStatus --cases=draft,published');

it('parses relationships in inline cli string', function () {
    $schema = $this->parser->execute('title:string, user:belongsTo(User), comments:hasMany(Comment)');

    expect($schema->fields)->toHaveKey('title')
        ->and($schema->relationships)->toHaveCount(2)
        ->and($schema->relationships['user'])->toBeInstanceOf(RelationshipDefinition::class)
        ->and($schema->relationships['user']->type)->toBe('belongsTo')
        ->and($schema->relationships['user']->relatedModel)->toBe('User')
        ->and($schema->relationships['comments']->type)->toBe('hasMany')
        ->and($schema->relationships['comments']->relatedModel)->toBe('Comment');
});

it('reads single-line text as the CLI form even when a default holds a colon and a space', function () {
    $schema = (new ParseSchemaAction)->execute('title:string:default("a: b"),views:integer');

    expect(array_keys($schema->fields))->toBe(['title', 'views'])
        ->and($schema->fields['title']->default->value)->toBe('a: b');
});

it('reads inline text as YAML only when it has a newline', function () {
    $schema = (new ParseSchemaAction)->execute("fields:\n  title: string\n  views: integer");

    expect(array_keys($schema->fields))->toBe(['title', 'views']);
});
