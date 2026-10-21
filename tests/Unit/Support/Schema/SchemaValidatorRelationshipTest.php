<?php

// Rules 11 to 13: relationships
it('accepts the supported relationship types in any case', function () {
    expect(issueCodes('a_id:integer,user:belongsTo(User),posts:hasMany(Post),profile:hasOne,tags:belongsToMany(Tag)'))
        ->toBe(['warning:relationship_foreign_key'])
        ->and(issueCodes('user_id:integer,user:BelongsTo(User)'))->toBe([]);
});

it('rejects the through and morph families as unsupported, and unknown types, listing the valid ones', function (string|array $relationship, string $word) {
    $schema = is_array($relationship) ? ['relationships' => ['owner' => $relationship]] : $relationship;
    $issue = schemaIssues($schema)[0];

    expect($issue->code)->toBe('relationship_type')->and($issue->message)->toContain($word)->and($issue->alternatives)->toBe(['belongsTo', 'hasOne', 'hasMany', 'belongsToMany']);
})->with([
    ['owner:morphTo', 'not supported'],
    ['owner:morphMany(Comment)', 'not supported'],
    ['owner:hasManyThrough(Post)', 'not supported'],
    ['owner:hasOneThrough(Post)', 'not supported'],
    ['owner:hasThrough(Post)', 'not supported'],
    ['owner:morphToMany(Tag)', 'not supported'],
    [['type' => 'belongsTu'], 'unknown type'],
    [['type' => 'hasManyThrough', 'model' => 'Post'], 'not supported'],
]);

it('reads a misspelt relationship type on the CLI as an unknown field type', function () {
    expect(issueCodes('owner:belongsTu(User)'))->toBe(['error:field_type']);
});

it('checks relationship names, models and keys', function (array $schema, array $codes) {
    expect(issueCodes($schema))->toBe($codes);
})->with([
    'name' => [['relationships' => ['Bad-Name' => 'hasMany(Post)']], ['error:relationship_name']],
    'model' => [['relationships' => ['posts' => 'hasMany(post model)']], ['error:relationship_model']],
    'key format' => [['relationships' => ['posts' => ['type' => 'hasMany', 'foreign_key' => 'Bad Key']]], ['error:relationship_key_name']],
    'belongsToMany with keys' => [['relationships' => ['tags' => ['type' => 'belongsToMany', 'foreign_key' => 'post_id']]], ['error:relationship_key_name']],
    'unknown key' => [['relationships' => ['posts' => ['type' => 'hasMany', 'modle' => 'Post']]], ['error:relationship_key']],
    'valid keys' => [['fields' => ['writer_id' => 'integer'], 'relationships' => ['posts' => ['type' => 'hasMany', 'foreign_key' => 'author_id']]], []],
    'malformed' => [['relationships' => ['posts' => 5]], ['error:relationship_shape', 'warning:relationship_foreign_key']],
]);

it('warns about a belongsTo whose foreign key is not a field', function () {
    $issue = schemaIssues('user:belongsTo(User)')[0];

    expect($issue->severity)->toBe('warning')->and($issue->message)->toContain('user_id')
        ->and(issueCodes('user_id:integer,user:belongsTo(User)'))->toBe([])
        ->and(issueCodes('writer:integer,user:belongsTo(User):writer'))->toBe([]);
});

it('accepts a model of the module and a User the module does not have, and refuses any other target', function () {
    $module = moduleWithEnum('<?php enum PostStatus: string {}');
    file_put_contents($module->path.'/Models/Category.php', '<?php class Category {}');
    $category = 'category_id:integer,category:belongsTo(Category)';

    expect(issueCodes($category.',user_id:integer,user:belongsTo(User)', $module))->toBe([])
        ->and(issueCodes($category.',post_id:integer,post:belongsTo(Post)', $module))->toBe(['error:relationship_target'])
        ->and(issueCodes('owner_id:integer,owner:belongsTo(\\App\\Models\\Owner)', $module))->toBe(['error:relationship_target'])
        ->and(issueCodes('post_id:integer,post:belongsTo(Post)'))->toBe([]);
});

it('names the models of the module when a relationship target is not one of them', function () {
    $module = moduleWithEnum('<?php enum PostStatus: string {}');
    file_put_contents($module->path.'/Models/Category.php', '<?php class Category {}');
    $message = schemaIssues('post_id:integer,post:belongsTo(Post)', $module)[0]->message;

    expect($message)->toContain('Post')->toContain('Category')->toContain('Blog');
    expect(schemaIssues('post_id:integer,post:belongsTo(Post)', moduleWithModels([]))[0]->message)->toContain('no models');
});

// Rule 14: unknown keys
it('reports unknown keys on a structured field and lists the valid keys', function () {
    $issue = schemaIssues(['title' => ['type' => 'string', 'nullabel' => true]])[0];

    expect($issue->code)->toBe('field_key')->and($issue->message)->toContain('nullabel')->and($issue->alternatives)->toContain('nullable', 'default', 'context');
});

it('accepts every structured key and its aliases', function () {
    expect(issueCodes(['fields' => [
        'a' => ['type' => 'decimal', 'precision' => 8, 'scale' => 2, 'nullable' => true, 'default' => '1.00'],
        'b' => ['type' => 'decimal', 'total_digits' => 8, 'decimal_places' => 2],
        'c' => ['type' => 'string', 'length' => 40, 'unique' => true, 'index' => true, 'fillable' => true, 'cast' => 'App\\X'],
        'd' => ['type' => 'foreignId', 'constrained' => 'users', 'cascade_on_delete' => true],
        'e' => ['type' => 'foreignId', 'nullable' => true, 'constrained' => true, 'null_on_delete' => true],
        'f' => ['type' => 'string', 'hashed' => true, 'guarded' => true, 'context' => true, 'modifiers' => ['index']],
        'g' => ['type' => 'string', 'type_arguments' => [10]],
        'h' => ['type' => 'integer', 'primary' => true, 'unsigned' => true],
    ]]))->toBe(['warning:field_modifier']);
});

it('collects every issue instead of stopping at the first', function () {
    $codes = issueCodes('Bad-Name:string,id:string,title:strng,views:integer:uniq,count:integer:default(abc),status:enum(draft,published),owner:morphTo');

    expect($codes)->toBe([
        'error:field_name', 'error:field_reserved', 'error:field_type', 'error:field_modifier',
        'error:field_default', 'error:field_enum', 'error:relationship_type',
    ]);
});
