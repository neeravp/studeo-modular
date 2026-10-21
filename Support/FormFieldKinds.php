<?php

namespace Studeo\Modular\Support;

use Studeo\Modular\DataObjects\FieldDefinition;

/**
 * Which kind of form control a field's column type calls for, shared by the Livewire and Vue form resolvers.
 */
final class FormFieldKinds
{
    private const array NUMERIC_TYPES = [
        'integer', 'tinyinteger', 'smallinteger', 'mediuminteger', 'biginteger',
        'unsignedinteger', 'unsignedtinyinteger', 'unsignedsmallinteger',
        'unsignedmediuminteger', 'unsignedbiginteger', 'int',
        'decimal', 'float', 'double',
        'foreignid', 'id',
    ];

    public static function isBoolean(FieldDefinition $field): bool
    {
        return self::in($field, ['boolean', 'bool']);
    }

    public static function isNumeric(FieldDefinition $field): bool
    {
        return self::in($field, self::NUMERIC_TYPES);
    }

    public static function isDecimal(FieldDefinition $field): bool
    {
        return self::in($field, ['decimal', 'float', 'double']);
    }

    public static function isTextarea(FieldDefinition $field): bool
    {
        return self::in($field, ['text', 'mediumtext', 'longtext']);
    }

    public static function isDate(FieldDefinition $field): bool
    {
        return self::in($field, ['date', 'datetime', 'timestamp']);
    }

    public static function isArray(FieldDefinition $field): bool
    {
        return self::in($field, ['json', 'jsonb']);
    }

    public static function isEnum(FieldDefinition $field): bool
    {
        return self::in($field, ['enum']);
    }

    /**
     * @param  array<int, string>  $types
     */
    private static function in(FieldDefinition $field, array $types): bool
    {
        return in_array(strtolower($field->type), $types, true);
    }
}
