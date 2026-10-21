<?php

use Studeo\Modular\Console\Actions\ParseCliSchemaAction;

beforeEach(function () {
    $this->action = new ParseCliSchemaAction;
});

it('parses fields with types and modifiers from cli token string', function () {
    $schema = $this->action->execute('title:string:unique,price:decimal:nullable,is_active:boolean:default(true)');

    expect($schema->fields)->toHaveCount(3)
        ->and($schema->fields['title']->type)->toBe('string')
        ->and($schema->fields['title']->isUnique())->toBeTrue()
        ->and($schema->fields['price']->type)->toBe('decimal')
        ->and($schema->fields['price']->isNullable())->toBeTrue()
        ->and($schema->fields['is_active']->default->value)->toBeTrue();
});

it('parses relationships from cli token string', function () {
    $schema = $this->action->execute('author:belongsTo(User):user_id:id,posts:hasMany(Post)');

    expect($schema->relationships)->toHaveCount(2)
        ->and($schema->relationships['author']->type)->toBe('belongsTo')
        ->and($schema->relationships['author']->relatedModel)->toBe('User')
        ->and($schema->relationships['author']->foreignKey)->toBe('user_id')
        ->and($schema->relationships['author']->ownerKey)->toBe('id')
        ->and($schema->relationships['posts']->type)->toBe('hasMany')
        ->and($schema->relationships['posts']->relatedModel)->toBe('Post');
});

it('parses an enum reference by class name from cli token string', function () {
    $schema = $this->action->execute('status:enum(PostStatus)');

    expect($schema->fields['status']->type)->toBe('enum')
        ->and($schema->fields['status']->enum)->toBe('PostStatus');
});

it('rejects an inline enum case list from cli token string', function () {
    $this->action->execute('status:enum(draft,published,archived)');
})->throws(InvalidArgumentException::class, 'module:enum PostStatus --cases=draft,published');

it('parses parameterized types from cli token string', function () {
    $schema = $this->action->execute('price:decimal(8,2):nullable,code:string(10):unique,short:char(2)');

    expect($schema->fields)->toHaveCount(3)
        ->and($schema->fields['price']->type)->toBe('decimal')
        ->and($schema->fields['price']->typeArguments)->toBe(['8', '2'])
        ->and($schema->fields['price']->isNullable())->toBeTrue()
        ->and($schema->fields['code']->type)->toBe('string')
        ->and($schema->fields['code']->typeArguments)->toBe(['10'])
        ->and($schema->fields['code']->isUnique())->toBeTrue()
        ->and($schema->fields['short']->type)->toBe('char')
        ->and($schema->fields['short']->typeArguments)->toBe(['2']);
});
