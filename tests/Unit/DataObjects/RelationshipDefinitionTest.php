<?php

use Studeo\Modular\DataObjects\RelationshipDefinition;
use Studeo\Modular\DataObjects\SchemaDefinition;

it('derives relationship method name and class from type accurately', function (): void {
    $belongsTo = new RelationshipDefinition(name: 'user', type: 'belongsTo', relatedModel: 'User');
    $hasMany = new RelationshipDefinition(name: 'comments', type: 'hasmany', relatedModel: 'Comment');
    $morphToMany = new RelationshipDefinition(name: 'tags', type: 'morphtomany', relatedModel: 'Tag');

    expect($belongsTo->relationshipMethodName())->toBe('belongsTo')
        ->and($belongsTo->relationshipClass())->toBe('\Illuminate\Database\Eloquent\Relations\BelongsTo')
        ->and($hasMany->relationshipMethodName())->toBe('hasMany')
        ->and($hasMany->relationshipClass())->toBe('\Illuminate\Database\Eloquent\Relations\HasMany')
        ->and($morphToMany->relationshipMethodName())->toBe('morphToMany')
        ->and($morphToMany->relationshipClass())->toBe('\Illuminate\Database\Eloquent\Relations\MorphToMany');
});

it('identifies singular vs collection relations correctly', function (): void {
    $belongsTo = new RelationshipDefinition(name: 'user', type: 'belongsTo', relatedModel: 'User');
    $hasOne = new RelationshipDefinition(name: 'profile', type: 'hasone', relatedModel: 'Profile');
    $hasMany = new RelationshipDefinition(name: 'orders', type: 'hasMany', relatedModel: 'Order');

    expect($belongsTo->isSingular())->toBeTrue()
        ->and($hasOne->isSingular())->toBeTrue()
        ->and($hasMany->isSingular())->toBeFalse();
});

it('generates phpDocProperty for singular and collection relationships in the models namespace', function (): void {
    $belongsTo = new RelationshipDefinition(name: 'category', type: 'belongsTo', relatedModel: 'Category');
    $hasMany = new RelationshipDefinition(name: 'orders', type: 'hasMany', relatedModel: 'Order');

    expect($belongsTo->phpDocProperty('Studeo\\Blog\\Models'))->toBe('@property-read ?\Studeo\Blog\Models\Category $category')
        ->and($hasMany->phpDocProperty('Studeo\\Blog\\Models'))->toBe('@property-read \Illuminate\Database\Eloquent\Collection<int, \Studeo\Blog\Models\Order> $orders');
});

it('generates methodDefinition with target class and optional keys', function (): void {
    $simple = new RelationshipDefinition(name: 'category', type: 'belongsTo', relatedModel: 'Category');
    $withKeys = new RelationshipDefinition(name: 'parent', type: 'belongsTo', relatedModel: 'Category', foreignKey: 'parent_id', ownerKey: 'id');

    expect($simple->methodDefinition())->toContain('public function category(): \Illuminate\Database\Eloquent\Relations\BelongsTo')
        ->and($simple->methodDefinition())->toContain('return $this->belongsTo(Category::class);')
        ->and($withKeys->methodDefinition())->toContain("return \$this->belongsTo(Category::class, 'parent_id', 'id');");
});

it('points a relationship to the application user at the configured class, with no import', function (): void {
    $user = new RelationshipDefinition(name: 'author', type: 'belongsTo', relatedModel: 'User', foreignKey: 'author_id', authUser: true);
    $users = new RelationshipDefinition(name: 'editors', type: 'hasMany', relatedModel: 'User', authUser: true);

    expect($user->methodDefinition())->toContain("return \$this->belongsTo(config('auth.providers.users.model'), 'author_id');")
        ->and($users->methodDefinition())->toContain("return \$this->hasMany(config('auth.providers.users.model'));")
        ->and($user->phpDocProperty('Studeo\\Blog\\Models'))->toBe('@property-read ?\Illuminate\Contracts\Auth\Authenticatable $author')
        ->and($users->phpDocProperty('Studeo\\Blog\\Models'))->toBe('@property-read \Illuminate\Database\Eloquent\Collection<int, \Illuminate\Contracts\Auth\Authenticatable> $editors');
});

it('documents the application user as nullable exactly as it documents a model of the module', function (): void {
    $inModule = new RelationshipDefinition(name: 'category', type: 'belongsTo', relatedModel: 'Category');
    $authUser = new RelationshipDefinition(name: 'author', type: 'belongsTo', relatedModel: 'User', authUser: true);
    $hasMany = new RelationshipDefinition(name: 'editors', type: 'hasMany', relatedModel: 'User', authUser: true);

    expect($inModule->phpDocProperty('Studeo\\Blog\\Models'))->toStartWith('@property-read ?\\')
        ->and($authUser->phpDocProperty('Studeo\\Blog\\Models'))->toStartWith('@property-read ?\\')
        ->and($hasMany->phpDocProperty('Studeo\\Blog\\Models'))->not->toContain('?');
});

it('resolves only a User the module does not have to the application user', function (): void {
    $schema = new SchemaDefinition(relationships: [
        'user' => new RelationshipDefinition(name: 'user', type: 'belongsTo', relatedModel: 'User'),
        'category' => new RelationshipDefinition(name: 'category', type: 'belongsTo', relatedModel: 'Category'),
    ]);

    expect($schema->forModels(['Category'])->relationships['user']->authUser)->toBeTrue()
        ->and($schema->forModels(['Category'])->relationships['category']->authUser)->toBeFalse()
        ->and($schema->forModels(['Category', 'User'])->relationships['user']->authUser)->toBeFalse();
});
