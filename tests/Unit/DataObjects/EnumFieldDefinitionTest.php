<?php

use Studeo\Modular\Console\Actions\ParseCliSchemaAction;
use Studeo\Modular\Console\Actions\ParseSchemaAction;
use Studeo\Modular\Console\Actions\ParseStructuredFieldAction;
use Studeo\Modular\DataObjects\FieldDefinition;
use Studeo\Modular\Support\RuleExpression;
use Studeo\Modular\Support\ValidationRuleResolver;

function enumField(string $token = 'status:enum(PostStatus)'): FieldDefinition
{
    return (new ParseCliSchemaAction)->execute($token)->forModule('Studeo\\Blog')->fields[explode(':', $token)[0]];
}

it('parses an inline enum reference as a class name', function () {
    $field = (new ParseCliSchemaAction)->parseFieldFromString('status:enum(PostStatus):nullable');

    expect($field->type)->toBe('enum')->and($field->enum)->toBe('PostStatus')->and($field->isNullable())->toBeTrue();
});

it('parses a structured enum reference from a file shape', function () {
    $field = (new ParseStructuredFieldAction)->execute('status', ['type' => 'enum', 'enum' => 'PostStatus', 'nullable' => true]);

    expect($field->type)->toBe('enum')->and($field->enum)->toBe('PostStatus');
});

it('resolves the enum class to the module Enums namespace', function () {
    expect(enumField()->enum)->toBe('Studeo\\Blog\\Enums\\PostStatus');
});

it('gives an enum field its class as PHP type, nullable as ?T', function () {
    expect(enumField()->phpType())->toBe('\\Studeo\\Blog\\Enums\\PostStatus')
        ->and(enumField('status:enum(PostStatus):nullable')->phpType())->toBe('?\\Studeo\\Blog\\Enums\\PostStatus')
        ->and(enumField()->phpDocProperty())->toBe('@property \\Studeo\\Blog\\Enums\\PostStatus $status');
});

it('casts an enum field to the enum class', function () {
    expect(enumField()->castType())->toBe('Studeo\\Blog\\Enums\\PostStatus');
});

it('stores an enum as a 30 character indexed string, never ->enum()', function () {
    expect(enumField()->migrationDefinition())->toBe("\$table->string('status', 30)->index();")
        ->and(enumField('status:enum(PostStatus):nullable')->migrationDefinition())->toBe("\$table->string('status', 30)->index()->nullable();")
        ->and(enumField('status:enum(PostStatus):index')->migrationDefinition())->toBe("\$table->string('status', 30)->index();");
});

it('fakes an enum with its cases', function () {
    expect(enumField()->fakerDefinition())->toBe('fake()->randomElement(\\Studeo\\Blog\\Enums\\PostStatus::cases())');
});

it('validates an enum with Rule::enum as a PHP expression', function () {
    $rules = ValidationRuleResolver::resolve(enumField());

    expect($rules[0])->toBe('required')
        ->and($rules[1])->toBeInstanceOf(RuleExpression::class)
        ->and($rules[1]->code)->toBe('\\Illuminate\\Validation\\Rule::enum(\\Studeo\\Blog\\Enums\\PostStatus::class)')
        ->and(ValidationRuleResolver::resolve(enumField('status:enum(PostStatus):nullable'))[0])->toBe('nullable');
});

it('refuses to describe an enum whose module has not been resolved', function () {
    (new ParseCliSchemaAction)->parseFieldFromString('status:enum(PostStatus)')->phpType();
})->throws(LogicException::class, 'PostStatus');

it('never emits exists or unique rules', function (string $token) {
    $rules = array_map(ValidationRuleResolver::resolve(...), (new ParseCliSchemaAction)->execute($token)->forModule('Studeo\\Blog')->fields);

    expect(json_encode($rules))->not->toContain('unique')->not->toContain('exists');
})->with([
    'unique modifier' => 'slug:string:unique',
    'foreignId' => 'author_id:foreignId',
    'constrained foreignId' => "owner_id:foreignId:constrained('users')",
    'id suffix heuristic' => 'legacy_id:integer',
    'unique enum' => 'status:enum(PostStatus):unique',
]);

it('keeps unique and constrained in the migration', function () {
    $schema = (new ParseCliSchemaAction)->execute("slug:string:unique,author_id:foreignId:constrained('users')");

    expect($schema->fields['slug']->migrationDefinition())->toBe("\$table->string('slug')->unique();")
        ->and($schema->fields['author_id']->migrationDefinition())->toBe("\$table->foreignId('author_id')->constrained('users');");
});

it('rejects a legacy inline case list and points at module:enum', function (string $token) {
    (new ParseCliSchemaAction)->execute($token);
})->with([
    'cases' => 'status:enum(draft,published)',
    'single lower case' => 'status:enum(draft)',
    'bare enum' => 'status:enum',
])->throws(InvalidArgumentException::class, 'module:enum PostStatus --cases=draft,published');

it('rejects legacy structured enum shapes and points at module:enum', function (array $config) {
    (new ParseStructuredFieldAction)->execute('status', $config);
})->with([
    'cases' => [['type' => 'enum', 'cases' => ['draft', 'published']]],
    'enum_cases' => [['type' => 'enum', 'enum_cases' => ['draft']]],
    'inline list' => [['type' => 'enum(draft,published)']],
    'no class' => [['type' => 'enum']],
    'cases beside class' => [['type' => 'enum', 'enum' => 'PostStatus', 'cases' => ['draft']]],
])->throws(InvalidArgumentException::class, 'module:enum PostStatus --cases=draft,published');

it('names the field and an example in the legacy error', function () {
    try {
        (new ParseSchemaAction)->execute('status:enum(draft,published)');
    } catch (InvalidArgumentException $exception) {
        expect($exception->getMessage())->toContain('[status]')->toContain('status:enum(PostStatus)')->toContain('draft,published');

        return;
    }

    throw new RuntimeException('expected a parse error');
});

it('emits ->enum( nowhere in the package', function () {
    $root = dirname(__DIR__, 3);
    $hits = [];

    foreach (['Support', 'Console', 'DataObjects', 'Providers'] as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$directory}", FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_contains((string) file_get_contents($file->getPathname()), '->enum(')) {
                $hits[] = str_replace($root.'/', '', $file->getPathname());
            }
        }
    }

    expect($hits)->toBe([]);
});
