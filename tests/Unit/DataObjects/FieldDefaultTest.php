<?php

use Studeo\Modular\Console\Actions\ParseCliSchemaAction;
use Studeo\Modular\Console\Actions\ParseSchemaAction;
use Studeo\Modular\Console\Actions\ParseStructuredFieldAction;
use Studeo\Modular\DataObjects\FieldDefault;

function fieldWithDefault(string $token): ?FieldDefault
{
    return (new ParseCliSchemaAction)->parseFieldFromString($token)->default;
}

it('types the default of each supported field type and renders it for every target', function (string $token, mixed $value, string $migration, string $livewire, string $vue) {
    $default = fieldWithDefault($token);

    expect($default)->toBeInstanceOf(FieldDefault::class)
        ->and($default->value)->toBe($value)
        ->and($default->migration())->toBe($migration)
        ->and($default->livewire())->toBe($livewire)
        ->and($default->vue())->toBe($vue);
})->with([
    'integer' => ['views:integer:default(5)', 5, '5', '5', '5'],
    'negative integer' => ['delta:bigInteger:default(-3)', -3, '-3', '-3', '-3'],
    'boolean true' => ['on:boolean:default(true)', true, 'true', 'true', 'true'],
    'boolean false' => ['on:bool:default(false)', false, 'false', 'false', 'false'],
    'bare string' => ['state:string:default(draft)', 'draft', "'draft'", "'draft'", "'draft'"],
    'quoted string' => ["state:string:default('two words')", 'two words', "'two words'", "'two words'", "'two words'"],
    'string with apostrophe' => ['label:string:default("it\'s")', "it's", "'it\\'s'", "'it\\'s'", "'it\\'s'"],
    'text' => ['body:text:default(none)', 'none', "'none'", "'none'", "'none'"],
    'decimal' => ['price:decimal(8,2):default(12.50)', '12.50', "'12.50'", "'12.50'", "'12.50'"],
    'decimal scale 0' => ['qty:decimal(8,0):default(3)', '3', "'3'", "'3'", "'3'"],
    'float' => ['ratio:float:default(1.5)', '1.5', '1.5', "'1.5'", '1.5'],
    'double from integer text' => ['rate:double:default(2)', '2', '2', "'2'", '2'],
    'enum value' => ['status:enum(PostStatus):default(draft)', 'draft', "'draft'", "'draft'", "'draft'"],
    'null on nullable' => ['note:string:nullable:default(null)', null, 'null', 'null', 'null'],
    'integer null on nullable' => ['views:integer:nullable:default(null)', null, 'null', 'null', 'null'],
]);

it('has no default when none is declared', function () {
    expect(fieldWithDefault('title:string'))->toBeNull();
});

it('rejects a default that does not fit its type, naming the field, type and token', function (string $token, string $field, string $type, string $badToken) {
    try {
        fieldWithDefault($token);
    } catch (InvalidArgumentException $exception) {
        expect($exception->getMessage())->toContain("[{$field}]")->toContain("[{$type}]")->toContain("[{$badToken}]");

        return;
    }

    throw new RuntimeException("expected {$token} to be rejected");
})->with([
    'word on integer' => ['views:integer:default(abc)', 'views', 'integer', 'abc'],
    'fraction on integer' => ['views:integer:default(1.5)', 'views', 'integer', '1.5'],
    'boolean on integer' => ['views:integer:default(true)', 'views', 'integer', 'true'],
    'negative unsigned' => ['views:unsignedInteger:default(-1)', 'views', 'unsignedInteger', '-1'],
    'word on boolean' => ['on:boolean:default(yes)', 'on', 'boolean', 'yes'],
    'number on boolean' => ['on:boolean:default(1)', 'on', 'boolean', '1'],
    'decimal without scale' => ['price:decimal(8,2):default(5)', 'price', 'decimal', '5'],
    'decimal too many places' => ['price:decimal(8,2):default(5.123)', 'price', 'decimal', '5.123'],
    'decimal word' => ['price:decimal(8,2):default(abc)', 'price', 'decimal', 'abc'],
    'decimal overflowing precision' => ['price:decimal(4,2):default(123.00)', 'price', 'decimal', '123.00'],
    'float word' => ['ratio:float:default(abc)', 'ratio', 'float', 'abc'],
    'string too long' => ['code:string(3):default(abcd)', 'code', 'string', 'abcd'],
    'enum value too long' => ['status:enum(PostStatus):default(a_value_that_is_far_longer_than_thirty)', 'status', 'enum', 'a_value_that_is_far_longer_than_thirty'],
    'null on required field' => ['title:string:default(null)', 'title', 'string', 'null'],
    'date' => ['born:date:default(2020-01-01)', 'born', 'date', '2020-01-01'],
    'datetime now' => ['at:datetime:default(now)', 'at', 'dateTime', 'now'],
    'timestamp' => ['ts:timestamp:default(CURRENT_TIMESTAMP)', 'ts', 'timestamp', 'CURRENT_TIMESTAMP'],
    'json' => ['meta:json:default([])', 'meta', 'json', '[]'],
    'uuid' => ['ref:uuid:default(abc)', 'ref', 'uuid', 'abc'],
    'ulid' => ['ref:ulid:default(abc)', 'ref', 'ulid', 'abc'],
    'foreignId' => ['owner_id:foreignId:default(1)', 'owner_id', 'foreignId', '1'],
]);

it('rejects an empty default', function () {
    fieldWithDefault('title:string:default()');
})->throws(InvalidArgumentException::class, '[title]');

it('types structured defaults the same way', function () {
    $parser = new ParseStructuredFieldAction;

    expect($parser->execute('views', ['type' => 'integer', 'default' => 0])->default->value)->toBe(0)
        ->and($parser->execute('on', ['type' => 'boolean', 'default' => false])->default->value)->toBeFalse()
        ->and($parser->execute('state', ['type' => 'string', 'default' => "it's"])->default->value)->toBe("it's")
        ->and($parser->execute('price', ['type' => 'decimal(8,2)', 'default' => '9.99'])->default->value)->toBe('9.99')
        ->and($parser->execute('note', ['type' => 'string', 'nullable' => true, 'default' => null])->default->value)->toBeNull()
        ->and($parser->execute('note', ['type' => 'string', 'nullable' => true, 'default' => null])->default)->toBeInstanceOf(FieldDefault::class);
});

it('rejects structured defaults that do not fit', function (string $name, array $config, string $token) {
    try {
        (new ParseStructuredFieldAction)->execute($name, $config);
    } catch (InvalidArgumentException $exception) {
        expect($exception->getMessage())->toContain("[{$name}]")->toContain("[{$token}]");

        return;
    }

    throw new RuntimeException('expected a parse error');
})->with([
    'word on integer' => ['views', ['type' => 'integer', 'default' => 'abc'], 'abc'],
    'quoted decimal string keeps the strict scale' => ['price', ['type' => 'decimal(8,2)', 'default' => '10.5'], '10.5'],
    'null on required' => ['title', ['type' => 'string', 'default' => null], 'null'],
    'json default' => ['meta', ['type' => 'json', 'default' => 'x'], 'x'],
]);

it('never emits a bareword default in a migration', function () {
    $field = (new ParseCliSchemaAction)->parseFieldFromString('state:string:default(draft)');

    expect($field->migrationDefinition())->toBe("\$table->string('state')->default('draft');");
});

it('keeps the default after the other modifiers in a migration', function () {
    $field = (new ParseCliSchemaAction)->parseFieldFromString('note:string:nullable:default(null)');

    expect($field->migrationDefinition())->toBe("\$table->string('note')->nullable()->default(null);");
});

it('formats a numeric structured decimal default to exactly the declared scale', function (int|float $number, string $type, string $expected) {
    $field = (new ParseStructuredFieldAction)->execute('price', ['type' => $type, 'default' => $number]);

    expect($field->default->value)->toBe($expected)
        ->and($field->migrationDefinition())->toContain("->default('{$expected}')");
})->with([
    'one place' => [10.5, 'decimal(8,2)', '10.50'],
    'whole number' => [10, 'decimal(8,2)', '10.00'],
    'exact scale' => [10.50, 'decimal(8,2)', '10.50'],
    'negative' => [-3.25, 'decimal(8,2)', '-3.25'],
    'negative whole' => [-3, 'decimal(8,2)', '-3.00'],
    'artefact prone' => [0.1, 'decimal(8,2)', '0.10'],
    'long but exact' => [1234.5678, 'decimal(10,4)', '1234.5678'],
    'scale zero' => [10, 'decimal(8,0)', '10'],
    'default scale' => [7.5, 'decimal', '7.50'],
]);

it('rejects a numeric structured decimal default with too many places or digits', function (int|float $number, string $type, string $token) {
    try {
        (new ParseStructuredFieldAction)->execute('price', ['type' => $type, 'default' => $number]);
    } catch (InvalidArgumentException $exception) {
        expect($exception->getMessage())->toContain('[price]')->toContain('[decimal]')->toContain("[{$token}]");

        return;
    }

    throw new RuntimeException('expected a parse error');
})->with([
    'too many places' => [10.505, 'decimal(8,2)', '10.505'],
    'places on scale zero' => [10.5, 'decimal(8,0)', '10.5'],
    'precision overflow whole' => [123, 'decimal(4,2)', '123.00'],
    'precision overflow fraction' => [123.5, 'decimal(4,2)', '123.50'],
    'negative overflow' => [-123.5, 'decimal(4,2)', '-123.50'],
]);

it('formats numeric decimal defaults read from yaml and json files', function () {
    $yaml = (new ParseSchemaAction)->execute("fields:\n  price:\n    type: decimal(8,2)\n    default: 10.5\n  qty:\n    type: decimal(8,2)\n    default: 10\n");
    $json = (new ParseSchemaAction)->execute('{"fields":{"price":{"type":"decimal(8,2)","default":10.5}}}');

    expect($yaml->fields['price']->default->value)->toBe('10.50')
        ->and($yaml->fields['qty']->default->value)->toBe('10.00')
        ->and($json->fields['price']->default->value)->toBe('10.50');
});

it('keeps the CLI decimal default strict about the declared scale', function () {
    fieldWithDefault('price:decimal(8,2):default(10.5)');
})->throws(InvalidArgumentException::class, '[10.5]');
