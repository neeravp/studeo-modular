<?php

use Studeo\Modular\DataObjects\FieldDefinition;

it('lets an explicit fillable value win over every modifier and name, as today', function (): void {
    expect((new FieldDefinition(name: 'x', modifiers: ['fillable'], fillable: false))->isFillable())->toBeFalse()
        ->and((new FieldDefinition(name: 'x', modifiers: ['guarded'], fillable: true))->isFillable())->toBeTrue()
        ->and((new FieldDefinition(name: 'id', type: 'id', fillable: true))->isFillable())->toBeTrue();
});

it('lets a guarded modifier win over a fillable modifier, as today', function (): void {
    expect((new FieldDefinition(name: 'x', modifiers: ['guarded', 'fillable']))->isFillable())->toBeFalse()
        ->and((new FieldDefinition(name: 'x', modifiers: ['fillable', 'guarded']))->isFillable())->toBeFalse();
});

it('lets a fillable modifier win over a default guarded name, as today', function (): void {
    expect((new FieldDefinition(name: 'Created_At', type: 'timestamp', modifiers: ['fillable']))->isFillable())->toBeTrue()
        ->and((new FieldDefinition(name: 'id', type: 'id', modifiers: ['fillable']))->isFillable())->toBeTrue();
});

it('guards the default names case-insensitively, as today', function (): void {
    foreach (['id', 'ID', 'Created_At', 'UPDATED_AT', 'deleted_at'] as $name) {
        expect((new FieldDefinition(name: $name))->isFillable())->toBeFalse();
    }

    expect((new FieldDefinition(name: 'identity'))->isFillable())->toBeTrue();
});

it('guards primary fields, and compares the id type case-sensitively, as today', function (): void {
    expect((new FieldDefinition(name: 'x', type: 'id'))->isFillable())->toBeFalse()
        ->and((new FieldDefinition(name: 'x', type: 'ID'))->isFillable())->toBeTrue()
        ->and((new FieldDefinition(name: 'x', modifiers: ['primary']))->isFillable())->toBeFalse();
});

it('prefers the explicit cast, trimmed, over the modifier cast, as today', function (): void {
    $field = new FieldDefinition(name: 'x', modifiers: ['cast(decimal:2)'], cast: ' custom ');

    expect($field->castType())->toBe('custom')
        ->and((new FieldDefinition(name: 'x', modifiers: ['cast(decimal:2)']))->castType())->toBe('decimal:2');
});

it('falls through a whitespace-only explicit cast, as today', function (): void {
    expect((new FieldDefinition(name: 'x', cast: '   '))->castType())->toBeNull()
        ->and((new FieldDefinition(name: 'x', type: 'integer', cast: ''))->castType())->toBe('integer');
});

it('returns an empty string for a cast modifier with an empty argument, as today', function (): void {
    expect((new FieldDefinition(name: 'x', modifiers: ['cast()']))->castType())->toBe('');
});

it('prefers the modifier cast over a hashed name, as today', function (): void {
    expect((new FieldDefinition(name: 'password', modifiers: ['cast(custom)']))->castType())->toBe('custom');
});

it('hashes password names case-insensitively and by the _password suffix, as today', function (string $name): void {
    expect((new FieldDefinition(name: $name))->castType())->toBe('hashed');
})->with(['password', 'PASSWORD', 'User_Password', 'api_password', '_password']);

it('does not hash names that only resemble password, as today', function (string $name): void {
    expect((new FieldDefinition(name: $name))->castType())->toBeNull();
})->with(['passwords', 'xpassword', 'password_', 'pass', 'password_hash']);

it('hashes any name that carries the hashed modifier, as today', function (): void {
    expect((new FieldDefinition(name: 'secret', modifiers: ['hashed']))->castType())->toBe('hashed')
        ->and((new FieldDefinition(name: 'secret', type: 'integer', modifiers: ['Hashed']))->castType())->toBe('hashed');
});

it('prefers hashed over the enum class and the type cast, as today', function (): void {
    $hashed = new FieldDefinition(name: 'password', type: 'enum', enum: 'A\\B');
    $enum = new FieldDefinition(name: 'kind', type: 'enum', enum: '\\A\\B');

    expect($hashed->castType())->toBe('hashed')
        ->and($enum->castType())->toBe('A\\B')
        ->and((new FieldDefinition(name: 'password', type: 'integer'))->castType())->toBe('hashed');
});

it('throws from castType for an unresolved enum, as today', function (): void {
    (new FieldDefinition(name: 'kind', type: 'enum', enum: 'Short'))->castType();
})->throws(LogicException::class, 'Enum [Short] on field [kind]');

it('reads the decimal scale from the second type argument, as today', function (): void {
    expect((new FieldDefinition(name: 'x', type: 'Decimal', typeArguments: ['8', '4']))->castType())->toBe('decimal:4')
        ->and((new FieldDefinition(name: 'x', type: 'DECIMAL', typeArguments: ['8', '0']))->castType())->toBe('decimal:0')
        ->and((new FieldDefinition(name: 'x', type: 'decimal', typeArguments: ['8', 'abc']))->castType())->toBe('decimal:abc');
});

it('uses the mapped decimal cast when there is no second argument, as today', function (): void {
    expect((new FieldDefinition(name: 'x', type: 'decimal', typeArguments: ['8']))->castType())->toBe('decimal:2')
        ->and((new FieldDefinition(name: 'x', type: 'decimal'))->castType())->toBe('decimal:2');
});

it('ignores a second type argument on other types and maps their casts, as today', function (): void {
    expect((new FieldDefinition(name: 'x', type: 'string', typeArguments: ['5', '3']))->castType())->toBeNull()
        ->and((new FieldDefinition(name: 'x', type: 'DateTime', typeArguments: ['1', '2']))->castType())->toBe('immutable_datetime')
        ->and((new FieldDefinition(name: 'x', type: 'bool'))->castType())->toBe('boolean')
        ->and((new FieldDefinition(name: 'x', type: 'uuid'))->castType())->toBeNull();
});
