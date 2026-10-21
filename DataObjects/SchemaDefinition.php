<?php

namespace Studeo\Modular\DataObjects;

/**
 * A parsed schema: a plain value. What a generator derives from it (columns, casts, rules,
 * form fields) comes from that generator's own resolver, not from here.
 */
final readonly class SchemaDefinition
{
    /**
     * @param  array<string, FieldDefinition>  $fields
     * @param  array<string, RelationshipDefinition>  $relationships
     */
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $table = null,
        public readonly array $fields = [],
        public readonly array $relationships = [],
    ) {}

    /**
     * The schema with its enum references resolved to the module's Enums namespace.
     */
    public function forModule(string $moduleNamespace): self
    {
        return new self(
            $this->name,
            $this->table,
            array_map(fn (FieldDefinition $field): FieldDefinition => $field->forModule($moduleNamespace), $this->fields),
            $this->relationships,
        );
    }

    /**
     * The schema with a relationship to a User the module has no model for pointing at the
     * application's user model.
     *
     * @param  array<int, string>  $moduleModels  the names of the module's models
     */
    public function forModels(array $moduleModels): self
    {
        $relationships = array_map(
            fn (RelationshipDefinition $relationship): RelationshipDefinition => $this->isUserWithoutModel($relationship, $moduleModels) ? $relationship->toAuthUser() : $relationship,
            $this->relationships,
        );

        return new self($this->name, $this->table, $this->fields, $relationships);
    }

    /**
     * @param  array<int, string>  $moduleModels
     */
    private function isUserWithoutModel(RelationshipDefinition $relationship, array $moduleModels): bool
    {
        return $relationship->relatedModel === 'User' && ! in_array('User', $moduleModels, true);
    }

    public function isEmpty(): bool
    {
        return empty($this->fields) && empty($this->relationships);
    }

    public function hasFields(): bool
    {
        return ! empty($this->fields);
    }

    public function hasRelationships(): bool
    {
        return ! empty($this->relationships);
    }
}
