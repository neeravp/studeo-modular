<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Studeo\Modular\DataObjects\FieldDefinition;
use Studeo\Modular\DataObjects\FieldModifier;
use Studeo\Modular\DataObjects\ModifierKind;
use Studeo\Modular\DataObjects\RawField;
use Studeo\Modular\DataObjects\RawRelationship;
use Studeo\Modular\DataObjects\RawSchema;
use Studeo\Modular\DataObjects\RelationshipDefinition;
use Studeo\Modular\DataObjects\SchemaDefinition;
use Studeo\Modular\Support\EnumFieldReference;

/**
 * Builds the definitions every generator uses from a raw schema. It is the last step after the
 * validator, so a schema that got this far is sound; read directly (as the parsers do), it still
 * fails on the first bad enum reference, modifier or default.
 */
class BuildSchemaDefinitionAction
{
    public function schema(RawSchema $raw): SchemaDefinition
    {
        $fields = [];
        $relationships = [];

        foreach ($raw->fields as $field) {
            $fields[$field->name] = $this->field($field);
        }

        foreach ($raw->relationships as $relationship) {
            $relationships[$relationship->name] = $this->relationship($relationship);
        }

        return new SchemaDefinition(
            name: is_string($raw->name) ? $raw->name : null,
            table: is_string($raw->table) ? $raw->table : null,
            fields: $fields,
            relationships: $relationships,
        );
    }

    public function field(RawField $raw): FieldDefinition
    {
        $modifiers = $this->modifiers($raw);

        return new FieldDefinition(
            name: $raw->name,
            type: $raw->canonicalType(),
            modifiers: $modifiers,
            enum: $this->enumReference($raw),
            fillable: $this->fillable($modifiers),
            cast: collect($modifiers)->first(fn (FieldModifier $modifier): bool => $modifier->kind === ModifierKind::Cast)?->argument,
            typeArguments: $raw->typeArguments,
        );
    }

    public function relationship(RawRelationship $raw): RelationshipDefinition
    {
        return new RelationshipDefinition(
            name: $raw->name,
            type: $raw->type,
            relatedModel: $raw->relatedModel ?? Str::studly(Str::singular($raw->name)),
            foreignKey: $raw->foreignKey,
            ownerKey: $raw->ownerKey,
        );
    }

    /**
     * @return array<int, FieldModifier>
     */
    private function modifiers(RawField $raw): array
    {
        $modifiers = array_map(
            fn (string $token): FieldModifier => FieldModifier::parse($token) ?? throw new InvalidArgumentException("Field [{$raw->name}] has an unknown modifier [{$token}]."),
            $raw->modifiers,
        );

        if (! $this->hasDecimalNumber($raw)) {
            return $modifiers;
        }

        $others = array_filter($modifiers, fn (FieldModifier $modifier): bool => $modifier->kind !== ModifierKind::DefaultValue);

        return [...$others, new FieldModifier(ModifierKind::DefaultValue, $raw->defaultToken())];
    }

    private function enumReference(RawField $raw): ?string
    {
        return match (true) {
            $raw->canonicalType() !== 'enum' => null,
            $raw->legacyCases !== null => throw EnumFieldReference::legacy($raw->name, $raw->legacyCases),
            $raw->enum === null || $raw->enum === '' => throw EnumFieldReference::missing($raw->name),
            default => EnumFieldReference::classNameOrFail($raw->name, $raw->enum),
        };
    }

    /**
     * @param  array<int, FieldModifier>  $modifiers
     */
    private function fillable(array $modifiers): ?bool
    {
        $deciding = array_filter($modifiers, fn (FieldModifier $modifier): bool => in_array($modifier->kind, [ModifierKind::Guarded, ModifierKind::Fillable], true));
        $first = array_shift($deciding);

        return match ($first?->kind) {
            null => null,
            ModifierKind::Guarded => false,
            default => $first->argument !== 'false',
        };
    }

    private function hasDecimalNumber(RawField $raw): bool
    {
        return $raw->defaultNumber !== null && $raw->canonicalType() === 'decimal';
    }
}
