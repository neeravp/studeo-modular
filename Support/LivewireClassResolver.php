<?php

namespace Studeo\Modular\Support;

use Studeo\Modular\DataObjects\FieldDefinition;

final class LivewireClassResolver
{
    private const array INTEGER_TYPES = [
        'integer', 'tinyinteger', 'smallinteger', 'mediuminteger', 'biginteger',
        'unsignedinteger', 'unsignedtinyinteger', 'unsignedsmallinteger',
        'unsignedmediuminteger', 'unsignedbiginteger', 'int',
        'foreignid', 'id',
    ];

    private const array DECIMAL_TYPES = [
        'decimal', 'float', 'double',
    ];

    public static function resolvePropertyType(FieldDefinition $field): string
    {
        $type = match (true) {
            self::isBooleanField($field) => 'bool',
            self::isIntegerField($field) => 'int',
            self::isDecimalField($field) => 'string',
            default => 'string',
        };

        return self::isNullableProperty($field) ? "?{$type}" : $type;
    }

    public static function resolvePropertyDefault(FieldDefinition $field): string
    {
        if ($field->default !== null) {
            return $field->default->livewire();
        }

        if (self::isNullableProperty($field)) {
            return 'null';
        }

        return match (true) {
            self::isBooleanField($field) => 'false',
            default => "''",
        };
    }

    private static function isNullableProperty(FieldDefinition $field): bool
    {
        return $field->isNullable() || self::isNullableByDefault($field);
    }

    private static function isNullableByDefault(FieldDefinition $field): bool
    {
        return self::isIntegerField($field) || self::isDecimalField($field);
    }

    private static function isBooleanField(FieldDefinition $field): bool
    {
        return in_array(strtolower($field->type), ['boolean', 'bool'], true);
    }

    private static function isIntegerField(FieldDefinition $field): bool
    {
        return in_array(strtolower($field->type), self::INTEGER_TYPES, true);
    }

    private static function isDecimalField(FieldDefinition $field): bool
    {
        return in_array(strtolower($field->type), self::DECIMAL_TYPES, true);
    }
}
