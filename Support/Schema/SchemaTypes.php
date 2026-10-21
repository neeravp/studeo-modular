<?php

namespace Studeo\Modular\Support\Schema;

/**
 * The field types a schema may use: exactly those every layer (PHP type, cast, rule, column,
 * factory, forms) handles. Anything else is rejected, not passed through to the migration.
 */
final class SchemaTypes
{
    public const array TYPES = [
        'string', 'char', 'text', 'mediumText', 'longText',
        'integer', 'tinyInteger', 'smallInteger', 'mediumInteger', 'bigInteger',
        'unsignedInteger', 'unsignedTinyInteger', 'unsignedSmallInteger', 'unsignedMediumInteger', 'unsignedBigInteger',
        'boolean', 'decimal', 'float', 'double',
        'date', 'dateTime', 'timestamp',
        'json', 'jsonb', 'uuid', 'ulid',
        'foreignId', 'foreignUuid', 'foreignUlid',
        'enum',
    ];

    private const array INTEGERS = ['integer', 'tinyInteger', 'smallInteger', 'mediumInteger', 'bigInteger'];

    /** The longest string or char column, in characters. */
    public const int MAX_LENGTH = 65535;

    /**
     * The canonical spelling of a type name (case-insensitive, with bool for boolean), or null.
     */
    public static function canonical(string $type): ?string
    {
        $lower = strtolower(trim($type));
        $lower = $lower === 'bool' ? 'boolean' : $lower;

        $matches = array_filter(self::TYPES, fn (string $canonical): bool => strtolower($canonical) === $lower);

        return array_shift($matches);
    }

    /**
     * How many arguments a type takes: string and char a length, decimal a precision and a scale,
     * float a precision.
     *
     * @return array{0: int, 1: int}
     */
    public static function argumentCount(string $canonical): array
    {
        return match ($canonical) {
            'string', 'char', 'float' => [0, 1],
            'decimal' => [0, 2],
            default => [0, 0],
        };
    }

    public static function isInteger(string $canonical): bool
    {
        return in_array($canonical, self::INTEGERS, true);
    }

    public static function isText(string $canonical): bool
    {
        return in_array($canonical, ['string', 'char', 'text', 'mediumText', 'longText'], true);
    }

    public static function isForeignKey(string $canonical): bool
    {
        return in_array($canonical, ['foreignId', 'foreignUuid', 'foreignUlid'], true);
    }
}
