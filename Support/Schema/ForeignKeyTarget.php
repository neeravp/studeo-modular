<?php

namespace Studeo\Modular\Support\Schema;

use Illuminate\Support\Str;
use Studeo\Modular\DataObjects\FieldDefinition;
use Studeo\Modular\DataObjects\ModifierKind;

/**
 * The table a foreign key field points at. The migration writes ->constrained() and leaves the
 * table to Laravel, which takes the column name without its _id and pluralises it; this applies
 * that same rule, so a check generated from the schema and the migration agree. constrained('x')
 * names the table outright.
 */
final class ForeignKeyTarget
{
    public static function isForeignKey(FieldDefinition $field): bool
    {
        return SchemaTypes::isForeignKey((string) SchemaTypes::canonical($field->type)) || $field->modifier(ModifierKind::Constrained) !== null;
    }

    public static function table(FieldDefinition $field): string
    {
        return $field->modifier(ModifierKind::Constrained)->argument ?? Str::plural(Str::beforeLast($field->name, '_id'));
    }
}
