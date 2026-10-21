<?php

use Studeo\Modular\Console\Actions\ParseCliSchemaAction;
use Studeo\Modular\Console\Actions\ParseSchemaAction;
use Studeo\Modular\Console\Actions\ParseStructuredSchemaAction;
use Studeo\Modular\DataObjects\RawField;
use Studeo\Modular\DataObjects\RawSchema;
use Studeo\Modular\Support\Schema\SchemaTypes;
use Studeo\Modular\Support\Schema\SchemaYamlWriter;
use Symfony\Component\Yaml\Yaml;

function yamlFor(string $cli, string $table = 'posts', ?string $model = null): string
{
    return (new SchemaYamlWriter)->dump((new ParseCliSchemaAction)->read($cli), $table, $model);
}

it('writes the table first, then fields, then relationships', function () {
    $yaml = yamlFor('title:string,author_id:integer,author:belongsTo(User)');

    expect($yaml)->toStartWith("table: posts\nfields:\n")
        ->and(strpos($yaml, 'table:'))->toBeLessThan(strpos($yaml, 'fields:'))
        ->and(strpos($yaml, 'fields:'))->toBeLessThan(strpos($yaml, 'relationships:'))
        ->and($yaml)->toEndWith("\n");
});

it('writes a plain field as the shorthand string', function () {
    expect(Yaml::parse(yamlFor('title:string,slug:string:unique,price:decimal(8,2):nullable:default(12.50),status:enum(PostStatus):default(draft)'))['fields'])->toBe([
        'title' => 'string',
        'slug' => 'string:unique',
        'price' => 'decimal(8,2):nullable:default(12.50)',
        'status' => 'enum(PostStatus):default(draft)',
    ]);
});

it('keeps the order the fields were written in', function () {
    expect(array_keys(Yaml::parse(yamlFor('c:string,a:string,b:string'))['fields']))->toBe(['c', 'a', 'b']);
});

it('writes a relationship as a shorthand, or as a map when it names keys', function () {
    $relationships = Yaml::parse(yamlFor('user_id:integer,user:belongsTo(User),posts:hasMany,tags:belongsToMany(Tag),owner:hasOne(Owner):owner_fk:id'))['relationships'];

    expect($relationships)->toBe([
        'user' => 'belongsTo(User)',
        'posts' => 'hasMany',
        'tags' => 'belongsToMany(Tag)',
        'owner' => ['type' => 'hasOne', 'model' => 'Owner', 'foreign_key' => 'owner_fk', 'owner_key' => 'id'],
    ]);
});

it('writes the map form when the shorthand would not read back as the same field', function () {
    $raw = new RawSchema(fields: [new RawField('x', type: 'a:b')]);

    $yaml = (new SchemaYamlWriter)->dump($raw, 'posts');

    expect(Yaml::parse($yaml)['fields']['x'])->toBe(['type' => 'a:b'])
        ->and((new ParseStructuredSchemaAction)->read(Yaml::parse($yaml))->fields[0])->toEqual($raw->fields[0]);
});

it('writes the model name only when it differs from the one the table implies', function () {
    expect(yamlFor('title:string', 'posts', 'Post'))->not->toContain('name:')
        ->and(yamlFor('title:string', 'people', 'Person'))->not->toContain('name:')
        ->and(yamlFor('title:string', 'blog_posts', 'Post'))->toStartWith("name: Post\ntable: blog_posts\n");
});

it('reads back as the schema it was written from, for every type, modifier and relationship', function () {
    $fields = collect(SchemaTypes::TYPES)->reject(fn (string $type): bool => $type === 'enum')->map(fn (string $type, int $index): string => "f{$index}:{$type}")->implode(',');
    $cli = $fields.',status:enum(PostStatus):default(draft),price:decimal(8,2):nullable:default(12.50),slug:string(40):unique:index,'
        .'note:string:nullable:default(null),label:string:default("it\'s: here, there"),owner_id:foreignId:nullable:constrained(users):nullOnDelete,'
        .'password:string:hashed:guarded,actor_id:integer:context,user_id:integer,user:belongsTo(User),posts:hasMany(Post),tags:belongsToMany(Tag)';

    $written = yamlFor($cli);

    $read = (new ParseSchemaAction)->execute($written);
    $original = (new ParseCliSchemaAction)->execute($cli);

    expect($read->table)->toBe('posts')
        ->and($read->fields)->toEqual($original->fields)
        ->and($read->relationships)->toEqual($original->relationships);
});
