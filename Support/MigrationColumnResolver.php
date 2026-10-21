<?php

namespace Studeo\Modular\Support;

use Studeo\Modular\DataObjects\FieldDefinition;
use Studeo\Modular\DataObjects\FieldModifier;
use Studeo\Modular\DataObjects\ModifierKind;
use Studeo\Modular\DataObjects\SchemaDefinition;

final class MigrationColumnResolver
{
    /**
     * The migration column statement of every field in the schema.
     *
     * @return array<int, string>
     */
    public static function columns(SchemaDefinition $schema): array
    {
        return array_values(array_map(self::resolve(...), $schema->fields));
    }

    public static function resolve(FieldDefinition $field): string
    {
        $column = self::buildBaseColumnDefinition($field);
        $modifiers = self::buildColumnModifiers($field);
        $default = $field->default === null ? '' : "->default({$field->default->migration()})";

        return "{$column}{$modifiers}{$default};";
    }

    private static function buildBaseColumnDefinition(FieldDefinition $field): string
    {
        $type = self::resolveMigrationMethod($field);

        return match ($type) {
            'enum' => self::buildEnumColumnDefinition($field),
            'id' => self::buildIdColumnDefinition($field),
            default => self::buildStandardColumnDefinition($field, $type),
        };
    }

    private static function buildStandardColumnDefinition(FieldDefinition $field, string $method): string
    {
        $arguments = self::resolveColumnArguments($field);

        return "\$table->{$method}(".implode(', ', $arguments).')';
    }

    /**
     * @return array<int, string>
     */
    private static function resolveColumnArguments(FieldDefinition $field): array
    {
        $formattedArguments = array_map(
            fn (string $arg): string => self::formatColumnArgument($arg),
            $field->typeArguments,
        );

        return ["'{$field->name}'", ...$formattedArguments];
    }

    private static function formatColumnArgument(string $arg): string
    {
        $trimmed = trim($arg);

        return match (true) {
            self::isLiteralValue($trimmed) => $trimmed,
            self::isAlreadyQuoted($trimmed) => $trimmed,
            default => "'{$trimmed}'",
        };
    }

    private static function isLiteralValue(string $value): bool
    {
        return is_numeric($value)
            || in_array(strtolower($value), ['true', 'false', 'null'], true);
    }

    private static function isAlreadyQuoted(string $value): bool
    {
        return (str_starts_with($value, "'") && str_ends_with($value, "'"))
            || (str_starts_with($value, '"') && str_ends_with($value, '"'));
    }

    /**
     * An enum is a PHP enum stored as a short indexed string, never a database ENUM.
     */
    private static function buildEnumColumnDefinition(FieldDefinition $field): string
    {
        return "\$table->string('{$field->name}', 30)->index()";
    }

    private static function buildIdColumnDefinition(FieldDefinition $field): string
    {
        return $field->name === 'id'
            ? '$table->id()'
            : "\$table->id('{$field->name}')";
    }

    private static function buildColumnModifiers(FieldDefinition $field): string
    {
        $applicable = array_filter($field->modifiers, fn (FieldModifier $modifier): bool => self::isColumnModifierOf($field, $modifier));

        return implode('', array_map(self::formatMigrationModifier(...), $applicable));
    }

    /**
     * An enum column is always indexed already, so an `index` modifier is not written again.
     */
    private static function isColumnModifierOf(FieldDefinition $field, FieldModifier $modifier): bool
    {
        return $modifier->kind->isColumnModifier() && ! ($field->isEnum() && $modifier->kind === ModifierKind::Index);
    }

    private static function resolveMigrationMethod(FieldDefinition $field): string
    {
        return match (strtolower($field->type)) {
            'int' => 'integer',
            'bigint' => 'bigInteger',
            'bool' => 'boolean',
            'datetime' => 'dateTime',
            default => $field->type,
        };
    }

    private static function formatMigrationModifier(FieldModifier $modifier): string
    {
        $argument = $modifier->argument === null ? '' : "'{$modifier->argument}'";

        return "->{$modifier->kind->value}({$argument})";
    }
}
