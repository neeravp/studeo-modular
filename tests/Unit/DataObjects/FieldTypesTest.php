<?php

use Studeo\Modular\Console\Actions\ParseCliSchemaAction;
use Studeo\Modular\DataObjects\FieldDefinition;

function typedField(string $token): FieldDefinition
{
    return (new ParseCliSchemaAction)->parseFieldFromString($token);
}

it('types decimals as strings, floats and doubles as floats', function () {
    expect(typedField('price:decimal(8,2)')->phpType())->toBe('string')
        ->and(typedField('price:decimal(8,2):nullable')->phpType())->toBe('?string')
        ->and(typedField('price:decimal(8,2)')->castType())->toBe('decimal:2')
        ->and(typedField('cost:decimal(10,3)')->castType())->toBe('decimal:3')
        ->and(typedField('ratio:float')->phpType())->toBe('float')
        ->and(typedField('rate:double:nullable')->phpType())->toBe('?float')
        ->and(typedField('ratio:float')->castType())->toBe('float');
});

it('types dates as CarbonImmutable with immutable casts', function (string $type, string $cast) {
    $field = typedField("at:{$type}");

    expect($field->phpType())->toBe('\\Carbon\\CarbonImmutable')
        ->and(typedField("at:{$type}:nullable")->phpType())->toBe('?\\Carbon\\CarbonImmutable')
        ->and($field->castType())->toBe($cast)
        ->and($field->phpDocProperty())->toBe('@property \\Carbon\\CarbonImmutable $at');
})->with([
    'date' => ['date', 'immutable_date'],
    'datetime' => ['datetime', 'immutable_datetime'],
    'timestamp' => ['timestamp', 'immutable_datetime'],
]);

it('writes every nullable PHP type as ?T', function () {
    expect(typedField('a:string:nullable')->phpType())->toBe('?string')
        ->and(typedField('a:integer:nullable')->phpType())->toBe('?int')
        ->and(typedField('a:boolean:nullable')->phpType())->toBe('?bool')
        ->and(typedField('a:json:nullable')->phpType())->toBe('?array')
        ->and(typedField('a:uuid:nullable')->phpType())->toBe('?string');
});

it('fakes decimals as strings with the declared scale, within the declared precision', function () {
    expect(typedField('price:decimal(8,2)')->fakerDefinition())->toBe("number_format(fake()->randomFloat(2, 0, 9999), 2, '.', '')")
        ->and(typedField('tiny:decimal(4,2)')->fakerDefinition())->toBe("number_format(fake()->randomFloat(2, 0, 99), 2, '.', '')")
        ->and(typedField('cost:decimal(10,3)')->fakerDefinition())->toBe("number_format(fake()->randomFloat(3, 0, 9999), 3, '.', '')")
        ->and(typedField('plain:decimal')->fakerDefinition())->toBe("number_format(fake()->randomFloat(2, 0, 9999), 2, '.', '')")
        ->and(typedField('whole:decimal(5,0)')->fakerDefinition())->toBe("number_format(fake()->randomFloat(0, 0, 9999), 0, '.', '')")
        ->and(typedField('ratio:float')->fakerDefinition())->toBe('fake()->randomFloat(2, 10, 1000)');
});
