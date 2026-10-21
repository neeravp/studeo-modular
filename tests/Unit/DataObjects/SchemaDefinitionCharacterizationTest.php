<?php

use Studeo\Modular\DataObjects\FieldDefinition;
use Studeo\Modular\DataObjects\RelationshipDefinition;
use Studeo\Modular\DataObjects\SchemaDefinition;

it('turns only an exact User the module has no model for into the auth user, as today', function (): void {
    $user = new RelationshipDefinition(name: 'author', type: 'belongsTo', relatedModel: 'User');
    $schema = new SchemaDefinition(relationships: ['author' => $user]);

    $resolved = $schema->forModels(['Post'])->relationships['author'];

    expect($resolved)->not->toBe($user)
        ->and($resolved->authUser)->toBeTrue()
        ->and($resolved->name)->toBe('author')
        ->and($resolved->type)->toBe('belongsTo')
        ->and($resolved->relatedModel)->toBe('User')
        ->and($user->authUser)->toBeFalse();
});

it('keeps the same relationship object when the module has a User model, as today', function (): void {
    $user = new RelationshipDefinition(name: 'author', type: 'belongsTo', relatedModel: 'User');
    $schema = new SchemaDefinition(relationships: ['author' => $user]);

    expect($schema->forModels(['Post', 'User'])->relationships['author'])->toBe($user);
});

it('compares User and the module models case-sensitively and strictly, as today', function (): void {
    $lower = new RelationshipDefinition(name: 'a', type: 'belongsTo', relatedModel: 'user');
    $upper = new RelationshipDefinition(name: 'b', type: 'belongsTo', relatedModel: 'User');
    $schema = new SchemaDefinition(relationships: ['a' => $lower, 'b' => $upper]);

    $resolved = $schema->forModels(['user']);

    expect($resolved->relationships['a'])->toBe($lower)
        ->and($resolved->relationships['b']->authUser)->toBeTrue();
});

it('keeps every other relationship as the same object, as today', function (): void {
    $category = new RelationshipDefinition(name: 'category', type: 'belongsTo', relatedModel: 'Category');
    $schema = new SchemaDefinition(relationships: ['category' => $category]);

    expect($schema->forModels([])->relationships['category'])->toBe($category);
});

it('keeps the relationship keys and their order, as today', function (): void {
    $schema = new SchemaDefinition(relationships: [
        'zeta' => new RelationshipDefinition(name: 'zeta', type: 'belongsTo', relatedModel: 'Category'),
        'owner' => new RelationshipDefinition(name: 'owner', type: 'belongsTo', relatedModel: 'User'),
        7 => new RelationshipDefinition(name: 'seven', type: 'hasMany', relatedModel: 'Tag'),
    ]);

    $resolved = $schema->forModels([]);

    expect(array_keys($resolved->relationships))->toBe(['zeta', 'owner', 7])
        ->and($resolved->relationships['owner']->authUser)->toBeTrue()
        ->and($resolved->relationships[7]->name)->toBe('seven');
});

it('leaves the name, table and fields untouched and handles no relationships, as today', function (): void {
    $title = new FieldDefinition(name: 'title');
    $fields = ['title' => $title];
    $schema = new SchemaDefinition('Post', 'posts', $fields);

    $resolved = $schema->forModels(['Post']);

    expect($resolved)->not->toBe($schema)
        ->and($resolved->name)->toBe('Post')
        ->and($resolved->table)->toBe('posts')
        ->and($resolved->fields)->toBe($fields)
        ->and($resolved->fields['title'])->toBe($title)
        ->and($resolved->relationships)->toBe([]);
});

it('resolves enums through forModule with the field keys and instances kept, as today', function (): void {
    $title = new FieldDefinition(name: 'title');
    $status = new FieldDefinition(name: 'status', type: 'enum', enum: 'PostStatus');
    $relationships = ['author' => new RelationshipDefinition(name: 'author', type: 'belongsTo', relatedModel: 'User')];
    $schema = new SchemaDefinition('Post', 'posts', ['title' => $title, 'status' => $status], $relationships);

    $resolved = $schema->forModule('Studeo\\Blog');

    expect(array_keys($resolved->fields))->toBe(['title', 'status'])
        ->and($resolved->fields['title'])->toBe($title)
        ->and($resolved->fields['status']->enum)->toBe('Studeo\\Blog\\Enums\\PostStatus')
        ->and($resolved->relationships)->toBe($relationships)
        ->and($resolved->name)->toBe('Post')
        ->and($resolved->table)->toBe('posts');
});
