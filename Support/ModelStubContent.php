<?php

namespace Studeo\Modular\Support;

use Studeo\Modular\DataObjects\FieldDefinition;
use Studeo\Modular\DataObjects\RelationshipDefinition;
use Studeo\Modular\DataObjects\SchemaDefinition;

/**
 * What a schema contributes to a model: docblock properties, fillable, casts and relationships,
 * both as data (for the drift check) and as the text of the Model stub.
 */
final class ModelStubContent
{
    /**
     * @return array<int, string>
     */
    public static function fillableFields(SchemaDefinition $schema): array
    {
        return array_values(array_map(
            fn (FieldDefinition $field): string => $field->name,
            array_filter($schema->fields, fn (FieldDefinition $field): bool => $field->isFillable()),
        ));
    }

    /**
     * Field => cast, as strings (an enum's cast is its class name).
     *
     * @return array<string, string>
     */
    public static function castMap(SchemaDefinition $schema): array
    {
        $cast = array_values(array_filter($schema->fields, fn (FieldDefinition $field): bool => $field->castType() !== null));

        return array_combine(
            array_map(fn (FieldDefinition $field): string => $field->name, $cast),
            array_map(fn (FieldDefinition $field): string => (string) $field->castType(), $cast),
        );
    }

    /**
     * The casts as the PHP expressions of the casts() array: quoted strings, enum classes as X::class.
     *
     * @return array<string, string>
     */
    public static function castExpressions(SchemaDefinition $schema, ?PhpImports $imports = null): array
    {
        $casts = self::castMap($schema);

        return array_combine(array_keys($casts), array_map(fn (string $name, string $cast): string => self::castExpression($schema->fields[$name], $cast, $imports), array_keys($casts), $casts));
    }

    private static function castExpression(FieldDefinition $field, string $cast, ?PhpImports $imports): string
    {
        return self::castsEnum($field, $cast) ? ($imports?->name($cast) ?? "\\{$cast}").'::class' : "'{$cast}'";
    }

    private static function castsEnum(FieldDefinition $field, string $cast): bool
    {
        return $field->isEnum() && $cast === $field->enumClass();
    }

    /**
     * @return array<int, string>
     */
    public static function phpDocProperties(SchemaDefinition $schema, string $modelNamespace, PhpImports $imports): array
    {
        return [
            ...array_values(array_map(fn (FieldDefinition $field): string => $field->phpDocProperty($imports), $schema->fields)),
            ...array_values(array_map(fn (RelationshipDefinition $relation): string => $relation->phpDocProperty($modelNamespace, $imports), $schema->relationships)),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function relationshipMethods(SchemaDefinition $schema, PhpImports $imports): array
    {
        return array_values(array_map(fn (RelationshipDefinition $relation): string => $relation->methodDefinition($imports), $schema->relationships));
    }

    /**
     * The docblock lines after the timestamps the stub declares, each ending in a newline.
     */
    public static function properties(SchemaDefinition $schema, string $modelsNamespace, PhpImports $imports): string
    {
        return implode('', array_map(fn (string $property): string => " * {$property}\n", self::phpDocProperties($schema, $modelsNamespace, $imports)));
    }

    /**
     * The $fillable array: `[]` when nothing is fillable, otherwise one field per line.
     */
    public static function fillable(SchemaDefinition $schema): string
    {
        $fillable = self::fillableFields($schema);

        return $fillable === []
            ? '[]'
            : "[\n".implode('', array_map(fn (string $field): string => "        '{$field}',\n", $fillable)).'    ]';
    }

    /**
     * The schema's casts, each on its own line after the timestamp casts the stub declares.
     */
    public static function casts(SchemaDefinition $schema, PhpImports $imports): string
    {
        $expressions = self::castExpressions($schema, $imports);

        return implode('', array_map(
            fn (string $expression, string $field): string => "\n            '{$field}' => {$expression},",
            $expressions,
            array_keys($expressions)
        ));
    }
}
