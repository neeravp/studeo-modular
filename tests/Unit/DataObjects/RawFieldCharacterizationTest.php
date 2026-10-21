<?php

use Studeo\Modular\DataObjects\RawField;

it('has no default token without modifiers or without a default modifier, as today', function (): void {
    expect((new RawField('a'))->defaultToken())->toBeNull()
        ->and((new RawField('a', modifiers: ['nullable', 'unique']))->defaultToken())->toBeNull();
});

it('returns the argument of the first default modifier, as written', function (): void {
    expect((new RawField('a', modifiers: ['default(5)']))->defaultToken())->toBe('5')
        ->and((new RawField('a', modifiers: ["default('two words')"]))->defaultToken())->toBe("'two words'")
        ->and((new RawField('a', modifiers: ['nullable', 'DEFAULT( x )']))->defaultToken())->toBe('x');
});

it('lets the first default win over a later one, as today', function (): void {
    expect((new RawField('a', modifiers: ['default(1)', 'default(2)']))->defaultToken())->toBe('1');
});

it('lets a bare default without an argument stop the search, as today', function (): void {
    expect((new RawField('a', modifiers: ['default', 'default(5)']))->defaultToken())->toBeNull();
});

it('returns an empty string for default() with empty parentheses, as today', function (): void {
    expect((new RawField('a', modifiers: ['default()']))->defaultToken())->toBe('');
});

it('skips tokens that do not parse before the default, as today', function (): void {
    expect((new RawField('a', modifiers: ['zzz', '!!', 'default(5)']))->defaultToken())->toBe('5');
});

it('ignores tokens that do not parse after the default, as today', function (): void {
    expect((new RawField('a', modifiers: ['default(5)', 'zzz', '!!', 'default']))->defaultToken())->toBe('5');
});

it('writes a decimal number at the declared scale and ignores the modifiers, as today', function (): void {
    expect((new RawField('a', 'decimal', ['8', '2'], defaultNumber: 5))->defaultToken())->toBe('5.00')
        ->and((new RawField('a', 'decimal', ['8', '2'], defaultNumber: 10.5))->defaultToken())->toBe('10.50')
        ->and((new RawField('a', 'decimal', ['8', '2'], defaultNumber: -3))->defaultToken())->toBe('-3.00')
        ->and((new RawField('a', 'decimal', ['8', '0'], defaultNumber: 5))->defaultToken())->toBe('5')
        ->and((new RawField('a', 'decimal', [], defaultNumber: 1.5))->defaultToken())->toBe('1.50')
        ->and((new RawField('a', 'decimal', ['8', '2'], ['default(9.99)'], defaultNumber: 5))->defaultToken())->toBe('5.00');
});

it('recognises the decimal type in any case and with padding, as today', function (): void {
    expect((new RawField('a', 'Decimal', ['8', '2'], defaultNumber: 5))->defaultToken())->toBe('5.00')
        ->and((new RawField('a', ' DECIMAL ', ['8', '2'], defaultNumber: 5))->defaultToken())->toBe('5.00');
});

it('ignores a default number on other types and falls through to the modifiers, as today', function (): void {
    expect((new RawField('a', 'string', modifiers: ['default(x)'], defaultNumber: 5))->defaultToken())->toBe('x')
        ->and((new RawField('a', 'string', defaultNumber: 5))->defaultToken())->toBeNull()
        ->and((new RawField('a', 'nonsense', ['8', '2'], defaultNumber: 5))->defaultToken())->toBeNull()
        ->and((new RawField('a', 'float', defaultNumber: 1.5))->defaultToken())->toBeNull();
});

it('throws the parser message for a decimal number with too many places, as today', function (): void {
    (new RawField('price', 'decimal', ['8', '2'], defaultNumber: 10.505))->defaultToken();
})->throws(InvalidArgumentException::class, 'Field [price]: default [10.505] is not valid for type [decimal]: more than 2 decimal places.');

it('throws the parser message for a decimal number of exponent size, as today', function (): void {
    (new RawField('price', 'decimal', ['8', '2'], defaultNumber: 1e30))->defaultToken();
})->throws(InvalidArgumentException::class, 'write numbers of this size as a quoted string');
