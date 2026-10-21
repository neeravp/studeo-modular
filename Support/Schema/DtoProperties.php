<?php

namespace Studeo\Modular\Support\Schema;

use Studeo\Modular\DataObjects\DtoKind;
use Studeo\Modular\DataObjects\FieldDefinition;
use Studeo\Modular\DataObjects\SchemaDefinition;

/**
 * Which fields of a schema a DTO carries, in what order, and as what type. The generator and
 * the drift check both ask here, so they cannot disagree.
 *
 * An Input carries the fillable fields and the context fields, required parameters first (the
 * schema's order within each group). An Output carries the id, every column except hashed and
 * guarded ones, and the timestamps, in the schema's order.
 */
final class DtoProperties
{
    /**
     * @return array<int, FieldDefinition>
     */
    public static function fields(SchemaDefinition $schema, DtoKind $kind): array
    {
        return $kind === DtoKind::Input ? self::input($schema) : self::output($schema);
    }

    /**
     * Whether the constructor parameter has a default: nullable (= null) or a declared default.
     */
    public static function isOptional(FieldDefinition $field): bool
    {
        return $field->isNullable() || $field->default !== null;
    }

    /**
     * The type as written in the DTO: a class by its short name, which the file then imports.
     */
    public static function type(FieldDefinition $field): string
    {
        return self::shorten($field->phpType());
    }

    /**
     * The class a field's type needs imported, without its leading backslash, or null.
     */
    public static function import(FieldDefinition $field): ?string
    {
        $type = ltrim($field->phpType(), '?');

        return str_starts_with($type, '\\') ? ltrim($type, '\\') : null;
    }

    /**
     * Property name => type, as the drift check compares them.
     *
     * @return array<string, string>
     */
    public static function expected(SchemaDefinition $schema, DtoKind $kind): array
    {
        $expected = [];

        foreach (self::fields($schema, $kind) as $field) {
            $expected[lcfirst(str($field->name)->studly()->toString())] = self::type($field);
        }

        return $expected;
    }

    /**
     * A type with its namespaces removed, so a fully qualified spelling equals the imported one.
     */
    public static function shorten(string $type): string
    {
        return (string) preg_replace('/\\\\?(?:\w+\\\\)+/', '', $type);
    }

    /**
     * @return array<int, FieldDefinition>
     */
    private static function input(SchemaDefinition $schema): array
    {
        $fields = array_values(array_filter($schema->fields, self::carriedByInput(...)));
        $required = array_values(array_filter($fields, fn (FieldDefinition $field): bool => ! self::isOptional($field)));
        $optional = array_values(array_filter($fields, fn (FieldDefinition $field): bool => self::isOptional($field)));

        return [...$required, ...$optional];
    }

    /**
     * @return array<int, FieldDefinition>
     */
    private static function output(SchemaDefinition $schema): array
    {
        $columns = array_filter($schema->fields, self::isOutputColumn(...));
        $before = isset($schema->fields['id']) ? [] : [new FieldDefinition('id', 'id')];
        $after = array_values(array_filter(
            [new FieldDefinition('created_at', 'timestamp'), new FieldDefinition('updated_at', 'timestamp')],
            fn (FieldDefinition $field): bool => ! isset($schema->fields[$field->name]),
        ));

        return [...$before, ...array_values($columns), ...$after];
    }

    private static function carriedByInput(FieldDefinition $field): bool
    {
        return $field->isFillable() || $field->isContext();
    }

    private static function isOutputColumn(FieldDefinition $field): bool
    {
        return ! $field->isGuarded() && $field->castType() !== 'hashed';
    }
}
