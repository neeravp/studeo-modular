<?php

use Studeo\Modular\Console\Actions\ParseSchemaAction;
use Studeo\Modular\DataObjects\SchemaIssue;
use Studeo\Modular\Exceptions\InvalidSchemaException;
use Studeo\Modular\Support\Schema\SchemaValidator;
use Studeo\Modular\Support\SchemaWarnings;
use Symfony\Component\Yaml\Exception\ParseException;

/**
 * The same broken schema in the CLI form, YAML and JSON: a bad name, a reserved name, an unknown
 * type, an unknown modifier, a bad default, a legacy enum list and an unsupported relationship.
 *
 * @return array<string, string>
 */
function brokenSchemas(): array
{
    return [
        'cli' => 'Bad-Name:string,id:string,title:strng,views:integer:uniq,count:integer:default(abc),status:enum(draft,published),owner:morphTo',
        'yaml' => "fields:\n  Bad-Name: string\n  id: string\n  title: strng\n  views: integer:uniq\n  count: integer:default(abc)\n  status: enum(draft,published)\nrelationships:\n  owner: morphTo\n",
        'json' => json_encode(['fields' => ['Bad-Name' => 'string', 'id' => 'string', 'title' => 'strng', 'views' => 'integer:uniq', 'count' => 'integer:default(abc)', 'status' => 'enum(draft,published)'], 'relationships' => ['owner' => 'morphTo']]),
    ];
}

/**
 * @return array<int, array{string, string, string, array<int, string>}>
 */
function issueShapes(string $schema): array
{
    try {
        (new ParseSchemaAction)->execute($schema);
    } catch (InvalidSchemaException $exception) {
        return array_map(fn (SchemaIssue $issue): array => [$issue->path, $issue->code, $issue->message, $issue->alternatives], $exception->issues);
    }

    throw new RuntimeException('expected the schema to be rejected');
}

beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/schema-parse-'.bin2hex(random_bytes(4));
    mkdir($this->directory);
});

afterEach(function () {
    array_map('unlink', glob("{$this->directory}/*") ?: []);
    rmdir($this->directory);
});

it('gives the same full issue list for the CLI form, YAML and JSON', function () {
    $lists = array_map(issueShapes(...), brokenSchemas());

    expect($lists['cli'])->toHaveCount(7)
        ->and($lists['yaml'])->toBe($lists['cli'])
        ->and($lists['json'])->toBe($lists['cli'])
        ->and(array_column($lists['cli'], 1))->toBe(['field_name', 'field_reserved', 'field_type', 'field_modifier', 'field_default', 'field_enum', 'relationship_type']);
});

it('gives the same issues for schema files as for inline text', function () {
    file_put_contents("{$this->directory}/s.yaml", brokenSchemas()['yaml']);
    file_put_contents("{$this->directory}/s.json", brokenSchemas()['json']);

    expect(issueShapes("{$this->directory}/s.yaml"))->toBe(issueShapes(brokenSchemas()['cli']))
        ->and(issueShapes("{$this->directory}/s.json"))->toBe(issueShapes(brokenSchemas()['cli']));
});

it('lists every issue with its valid alternatives in the exception message', function (string $schema) {
    try {
        (new ParseSchemaAction)->execute($schema);
    } catch (InvalidSchemaException $exception) {
        expect($exception)->toBeInstanceOf(InvalidArgumentException::class)
            ->and($exception->getMessage())->toStartWith('Invalid schema: 7 problems found.')
            ->and($exception->getMessage())
            ->toContain('fields.Bad-Name:')->toContain('fields.id:')->toContain('fields.title:')->toContain('fields.views:')
            ->toContain('fields.count:')->toContain('fields.status:')->toContain('relationships.owner:')
            ->toContain('Valid: string, char, text')->toContain('Valid: nullable, unique, index')->toContain('Valid: belongsTo, hasOne, hasMany, belongsToMany');

        return;
    }

    throw new RuntimeException('expected the schema to be rejected');
})->with(fn () => array_values(brokenSchemas()));

it('keeps a syntax error a single issue', function (string $schema, string $word) {
    $issues = issueShapes($schema);

    expect($issues)->toHaveCount(1)->and($issues[0][1])->toBe('schema_syntax')->and($issues[0][2])->toContain($word);
})->with([
    'broken json' => ['{ "fields": ', 'Invalid JSON'],
    'broken yaml' => ["fields:\n  - [unclosed\n  a: : b\n", 'Invalid YAML'],
]);

it('words a decode failure as the format, the place and the decoder\'s own message', function () {
    file_put_contents("{$this->directory}/bad.json", '{ "fields": ');
    file_put_contents("{$this->directory}/bad.yaml", "fields:\n  - [unclosed\n  a: : b\n");

    $cases = [
        ['{ "fields": ', 'Invalid JSON: ', JsonException::class],
        ["fields:\n  - [unclosed\n  a: : b\n", 'Invalid YAML: ', ParseException::class],
        ["{$this->directory}/bad.json", "Invalid JSON in schema file [{$this->directory}/bad.json]: ", JsonException::class],
        ["{$this->directory}/bad.yaml", "Invalid YAML in schema file [{$this->directory}/bad.yaml]: ", ParseException::class],
    ];

    foreach ($cases as [$schema, $prefix, $decoder]) {
        try {
            (new ParseSchemaAction)->execute($schema);
            $this->fail("[{$prefix}] was not rejected");
        } catch (InvalidSchemaException $exception) {
            expect($exception->getPrevious())->toBeInstanceOf($decoder)
                ->and($exception->issues)->toHaveCount(1)
                ->and($exception->issues[0]->code)->toBe('schema_syntax')
                ->and($exception->issues[0]->message)->toBe($prefix.$exception->getPrevious()->getMessage());
        }
    }
});

it('keeps an unreadable or non-map file a single issue', function () {
    file_put_contents("{$this->directory}/five.json", '5');

    expect(issueShapes("{$this->directory}/five.json"))->toHaveCount(1)
        ->and(issueShapes("{$this->directory}/five.json")[0][2])->toContain('must be a map');
});

it('returns the definition and keeps warnings aside when there are only warnings', function () {
    $warnings = new SchemaWarnings;
    $parser = new ParseSchemaAction(warnings: $warnings);

    $schema = $parser->execute('user:belongsTo(User)');

    expect($schema->relationships)->toHaveKey('user');

    $flushed = $warnings->flush();

    expect($flushed)->toHaveCount(1)->and($flushed[0]->severity)->toBe('warning')->and($warnings->flush())->toBe([]);
});

it('does not keep warnings when the schema is rejected', function () {
    $warnings = new SchemaWarnings;

    expect(fn () => (new ParseSchemaAction(warnings: $warnings))->execute('user:belongsTo(User),x:strng'))->toThrow(InvalidSchemaException::class)
        ->and($warnings->flush())->toBe([]);
});

it('still returns an empty definition for no input', function () {
    expect((new ParseSchemaAction)->execute(null)->isEmpty())->toBeTrue()
        ->and((new ParseSchemaAction)->execute('  ')->isEmpty())->toBeTrue();
});

it('builds the same definition the validator accepted', function () {
    $schema = (new ParseSchemaAction(validator: new SchemaValidator))->execute('title:string:default(Untitled),status:enum(PostStatus),author_id:foreignId:constrained(users)');

    expect($schema->fields['title']->default->value)->toBe('Untitled')
        ->and($schema->fields['status']->enum)->toBe('PostStatus')
        ->and($schema->fields['author_id']->type)->toBe('foreignId');
});
