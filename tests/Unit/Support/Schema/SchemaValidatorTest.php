<?php

use Studeo\Modular\Support\Schema\SchemaTypes;

it('accepts a schema that uses every supported type', function () {
    $fields = collect(SchemaTypes::TYPES)->reject(fn (string $type): bool => $type === 'enum')->map(fn (string $type, int $index): string => "f{$index}:{$type}")->implode(',');

    expect(schemaIssues($fields))->toBe([])
        ->and(schemaIssues('a:bool,b:Boolean,c:dateTime,d:datetime'))->toBe([]);
});

// Rule 1: the shape of the whole schema
it('reports a malformed or unknown top level of a structured schema', function () {
    expect(issueCodes(['fields' => ['title' => 'string'], 'feilds' => []]))->toBe(['error:schema_key'])
        ->and(issueCodes(['fields' => ['a', 'b']]))->toBe(['error:schema_shape', 'error:field_name', 'error:field_type', 'error:field_name', 'error:field_type'])
        ->and(issueCodes(['fields' => ['title' => 'string'], 'relationships' => 'belongsTo']))->toBe(['error:schema_shape'])
        ->and(schemaIssues(['fields' => [], 'oops' => 1])[0]->alternatives)->toBe(['name', 'table', 'fields', 'relationships', 'relations']);
});

it('treats a map without top-level keys as the fields themselves', function () {
    expect(issueCodes(['title' => 'string', 'body' => 'text:nullable']))->toBe([]);
});

// Rule 2: table and name
it('checks the table and model name formats', function () {
    expect(issueCodes(['table' => 'Blog Posts', 'fields' => ['a' => 'string']]))->toBe(['error:schema_table'])
        ->and(issueCodes(['table' => 'post', 'fields' => ['a' => 'string']]))->toBe([])
        ->and(issueCodes(['table' => 'blog_posts', 'name' => 'BlogPost', 'fields' => ['a' => 'string']]))->toBe([])
        ->and(issueCodes(['name' => 'blog post', 'fields' => ['a' => 'string']]))->toBe(['error:schema_name'])
        ->and(issueCodes(['table' => 5, 'fields' => ['a' => 'string']]))->toBe(['error:schema_table']);
});

// Rule 3: field names
it('rejects a field name that is not lower snake case', function (string $name) {
    expect(issueCodes("{$name}:string"))->toBe(['error:field_name']);
})->with(['Bad-Name', 'Title', '1st', 'my field', '']);

it('rejects the names the create migration declares, and the names that shadow a model property', function (string $name) {
    $issue = schemaIssues("{$name}:string")[0];

    expect($issue->code)->toBe('field_reserved')->and($issue->alternatives)->not->toBe([]);
})->with(['id', 'created_at', 'updated_at', 'deleted_at', 'pivot', 'exists', 'attributes']);

it('accepts ordinary snake case field names', function () {
    expect(issueCodes('author_id:foreignId,title:string,is_active:boolean,x2:integer'))->toBe([]);
});

// Rule 4: duplicates
it('reports a field declared twice and a field that is also a relationship', function () {
    expect(issueCodes('title:string,title:text'))->toBe(['error:field_duplicate'])
        ->and(issueCodes('author:string,author:belongsTo(User)'))->toBe(['error:name_clash', 'warning:relationship_foreign_key'])
        ->and(issueCodes('a:belongsTo(User),a:hasMany(Post)'))->toBe(['error:relationship_duplicate', 'warning:relationship_foreign_key']);
});

// Rule 5: types and arguments
it('rejects an unknown type and lists the valid types', function (string $type) {
    $issue = schemaIssues("x:{$type}")[0];

    expect($issue->code)->toBe('field_type')->and($issue->message)->toContain("[{$type}]")->and($issue->alternatives)->toBe(SchemaTypes::TYPES);
})->with(['strng', 'int', 'bigint', 'id', 'time', 'year', 'binary', 'ipAddress', 'morphs', 'softDeletes', 'rememberToken', 'string(']);

it('checks the type arguments', function (string $token, array $codes) {
    expect(issueCodes($token))->toBe($codes);
})->with([
    'zero length' => ['x:string(0)', ['error:field_type_arguments']],
    'too long' => ['x:string(70000)', ['error:field_type_arguments']],
    'word length' => ['x:string(abc)', ['error:field_type_arguments']],
    'precision too big' => ['x:decimal(70,2)', ['error:field_type_arguments']],
    'scale above precision' => ['x:decimal(4,5)', ['error:field_type_arguments']],
    'too many decimal arguments' => ['x:decimal(8,2,1)', ['error:field_type_arguments']],
    'arguments on an integer' => ['x:integer(5)', ['error:field_type_arguments']],
    'float precision out of range' => ['x:float(60)', ['error:field_type_arguments']],
    'valid string length' => ['x:string(255)', []],
    'valid decimal' => ['x:decimal(8,2)', []],
    'valid float precision' => ['x:float(24)', []],
    'valid char' => ['x:char(2)', []],
]);

// Rule 6: modifiers
it('rejects an unknown modifier and lists the valid ones', function () {
    $issue = schemaIssues('x:integer:uniq')[0];

    expect($issue->code)->toBe('field_modifier')->and($issue->alternatives)->toContain('nullable', 'unique', 'context');
});

it('checks modifier arguments, combinations and applicability', function (string $token, array $codes) {
    expect(issueCodes($token))->toBe($codes);
})->with([
    'argument on a flag' => ['x:string:unique(a)', ['error:field_modifier_argument']],
    'default without an argument' => ['x:string:default()', ['error:field_modifier_argument', 'error:field_default']],
    'fillable with a word' => ['x:string:fillable(maybe)', ['error:field_modifier_argument']],
    'constrained with a bad table' => ['x:foreignId:constrained(Bad Name)', ['error:field_modifier_argument']],
    'cast with nonsense' => ['x:string:cast(not a cast!)', ['error:field_modifier_argument']],
    'modifier twice' => ['x:string:nullable:nullable', ['error:field_modifier']],
    'unsigned on a string' => ['x:string:unsigned', ['error:field_modifier']],
    'constrained on an integer' => ['x:integer:constrained', ['error:field_modifier']],
    'hashed on an integer' => ['x:integer:hashed', ['error:field_modifier']],
    'cascade without constrained' => ['x:foreignId:cascadeOnDelete', ['error:field_modifier']],
    'null on delete without nullable' => ['x:foreignId:constrained:nullOnDelete', ['error:field_modifier']],
    'nullable primary' => ['x:integer:primary:nullable', ['error:field_modifier']],
    'fillable and guarded' => ['x:string:fillable:guarded', ['error:field_modifier']],
    'index beside unique warns' => ['x:string:unique:index', ['warning:field_modifier']],
    'valid foreign key' => ['x:foreignId:constrained(users):cascadeOnDelete', []],
    'valid nullable foreign key' => ['x:foreignId:nullable:constrained:nullOnDelete', []],
    'valid hashed string' => ['x:string:hashed', []],
    'valid unsigned integer' => ['x:integer:unsigned', []],
    'valid casts' => ['x:string:cast(decimal:2)', []],
    'valid class cast' => ['x:string:cast(App\\Casts\\Money)', []],
    'valid fillable' => ['x:string:fillable(false)', []],
    'valid context' => ['x:integer:context', []],
    'valid cascade short form' => ['x:foreignId:constrained:cascade', []],
]);
