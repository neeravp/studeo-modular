<?php

namespace Studeo\Modular\Support;

/**
 * Which PHP type and model cast a column type maps to, by lower-cased type name.
 */
final class FieldTypeMap
{
    private const array PHP_TYPES = [
        'id' => 'int',
        'foreignid' => 'int',
        'integer' => 'int',
        'tinyinteger' => 'int',
        'smallinteger' => 'int',
        'mediuminteger' => 'int',
        'biginteger' => 'int',
        'unsignedinteger' => 'int',
        'unsignedtinyinteger' => 'int',
        'unsignedsmallinteger' => 'int',
        'unsignedmediuminteger' => 'int',
        'unsignedbiginteger' => 'int',
        'boolean' => 'bool',
        'bool' => 'bool',
        'decimal' => 'string',
        'float' => 'float',
        'double' => 'float',
        'date' => '\Carbon\CarbonImmutable',
        'datetime' => '\Carbon\CarbonImmutable',
        'timestamp' => '\Carbon\CarbonImmutable',
        'json' => 'array',
        'jsonb' => 'array',
        'uuid' => 'string',
        'ulid' => 'string',
        'foreignuuid' => 'string',
        'foreignulid' => 'string',
    ];

    private const array CASTS = [
        'boolean' => 'boolean',
        'bool' => 'boolean',
        'integer' => 'integer',
        'tinyinteger' => 'integer',
        'smallinteger' => 'integer',
        'mediuminteger' => 'integer',
        'biginteger' => 'integer',
        'unsignedinteger' => 'integer',
        'unsignedtinyinteger' => 'integer',
        'unsignedsmallinteger' => 'integer',
        'unsignedmediuminteger' => 'integer',
        'unsignedbiginteger' => 'integer',
        'decimal' => 'decimal:2',
        'float' => 'float',
        'double' => 'float',
        'date' => 'immutable_date',
        'datetime' => 'immutable_datetime',
        'timestamp' => 'immutable_datetime',
        'json' => 'array',
        'jsonb' => 'array',
    ];

    public static function phpType(string $type): ?string
    {
        return self::PHP_TYPES[strtolower($type)] ?? null;
    }

    public static function cast(string $type): ?string
    {
        return self::CASTS[strtolower($type)] ?? null;
    }
}
