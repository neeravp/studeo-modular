<?php

use Studeo\Modular\Console\Actions\ParseCliSchemaAction;
use Studeo\Modular\Support\Schema\SchemaYamlWriter;

beforeEach(function () {
    $this->yaml = fn (string $cli): string => (new SchemaYamlWriter)->dump((new ParseCliSchemaAction)->read($cli), 'posts');
});

it('writes a type with arguments and an enum with its modifiers as shorthand', function () {
    expect(($this->yaml)('price:decimal(8,2):nullable,status:enum(PostStatus):default(draft)'))->toBe(
        "table: posts\nfields:\n  price: 'decimal(8,2):nullable'\n  status: 'enum(PostStatus):default(draft)'\n"
    );
});

it('writes a bare name as a string field', function () {
    expect(($this->yaml)('title,note::nullable'))->toBe("table: posts\nfields:\n  title: string\n  note: 'string:nullable'\n");
});

it('writes a default holding a colon, a comma and a quote as shorthand', function () {
    expect(($this->yaml)('label:string:default("it\'s: here, there")'))->toBe(
        "table: posts\nfields:\n  label: 'string:default(\"it''s: here, there\")'\n"
    );
});

it('writes a type with an unclosed parenthesis as it was written', function () {
    expect(($this->yaml)('bad:decimal(8'))->toBe("table: posts\nfields:\n  bad: decimal(8\n");
});

it('writes a padded token with empty parts as the trimmed shorthand', function () {
    expect(($this->yaml)(' title : text :: nullable : :unique '))->toBe("table: posts\nfields:\n  title: 'text:nullable:unique'\n");
});
