<?php

namespace Studeo\Modular\Support\Schema;

/**
 * The keys the structured (YAML and JSON) schema form accepts. Anything else is reported,
 * so a typo such as "nullabel" cannot be silently ignored.
 */
final class SchemaKeys
{
    public const array ROOT = ['name', 'table', 'fields', 'relationships', 'relations'];

    /** Modifier keys: a true value adds the modifier of the same name. */
    public const array FLAGS = ['nullable', 'unique', 'index', 'primary', 'unsigned', 'hashed', 'guarded', 'context'];

    public const array FIELD = [
        'type', 'length', 'precision', 'total_digits', 'scale', 'decimal_places', 'type_arguments',
        'enum', 'cases', 'enum_cases',
        'nullable', 'unique', 'index', 'primary', 'unsigned', 'hashed', 'guarded', 'context',
        'fillable', 'constrained', 'cascade_on_delete', 'cascade', 'null_on_delete', 'default', 'cast', 'modifiers',
    ];

    public const array RELATIONSHIP = ['type', 'model', 'related', 'foreign_key', 'owner_key'];
}
