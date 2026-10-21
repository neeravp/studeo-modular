<?php

namespace Studeo\Modular\Support\Schema;

use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\RawField;
use Studeo\Modular\DataObjects\RawRelationship;
use Studeo\Modular\DataObjects\RawSchema;
use Studeo\Modular\DataObjects\SchemaIssue;

/**
 * Applies every schema rule and collects every issue; it never throws and never stops at the
 * first. Rules that need the module (enum files, relationship targets) run only when a module
 * is given.
 */
final class SchemaValidator
{
    public function __construct(
        private readonly FieldRules $fields = new FieldRules,
        private readonly RelationshipRules $relationships = new RelationshipRules,
    ) {}

    /**
     * @return array<int, SchemaIssue>
     */
    public function validate(RawSchema $schema, ?ModuleDefinition $module = null): array
    {
        $fieldNames = array_map(fn (RawField $field): string => $field->name, $schema->fields);

        return [
            ...$this->root($schema),
            ...$this->duplicates($schema),
            ...array_merge([], ...array_map(fn (RawField $field): array => $this->fields->check($field, $module), $schema->fields)),
            ...array_merge([], ...array_map(fn (RawRelationship $relationship): array => $this->relationships->check($relationship, $module, $fieldNames), $schema->relationships)),
        ];
    }

    /**
     * @return array<int, SchemaIssue>
     */
    private function root(RawSchema $schema): array
    {
        return [
            ...array_map(fn (string $problem): SchemaIssue => SchemaIssue::error('schema', 'schema_shape', "The schema {$problem}."), $schema->malformed),
            ...($schema->unknownKeys === [] ? [] : [SchemaIssue::error('schema', 'schema_key', 'Unknown top-level key(s) ['.implode(', ', $schema->unknownKeys).'].', SchemaKeys::ROOT)]),
            ...$this->text($schema->table, 'table', '/^[a-z][a-z0-9_]*$/', 'a lower snake case table name, e.g. blog_posts'),
            ...$this->text($schema->name, 'name', '/^[A-Z][A-Za-z0-9]*$/', 'a StudlyCase model name, e.g. BlogPost'),
        ];
    }

    /**
     * @return array<int, SchemaIssue>
     */
    private function text(mixed $value, string $key, string $pattern, string $expected): array
    {
        return $value === null || (is_string($value) && preg_match($pattern, $value) === 1)
            ? []
            : [SchemaIssue::error($key, "schema_{$key}", "The schema {$key} must be {$expected}.")];
    }

    /**
     * @return array<int, SchemaIssue>
     */
    private function duplicates(RawSchema $schema): array
    {
        $fieldNames = array_map(fn (RawField $field): string => $field->name, $schema->fields);
        $relationshipNames = array_map(fn (RawRelationship $relationship): string => $relationship->name, $schema->relationships);

        $issues = [];

        foreach (array_keys(array_filter(array_count_values($fieldNames), fn (int $count): bool => $count > 1)) as $name) {
            $issues[] = SchemaIssue::error("fields.{$name}", 'field_duplicate', "Field [{$name}] is declared more than once.");
        }

        foreach (array_keys(array_filter(array_count_values($relationshipNames), fn (int $count): bool => $count > 1)) as $name) {
            $issues[] = SchemaIssue::error("relationships.{$name}", 'relationship_duplicate', "Relationship [{$name}] is declared more than once.");
        }

        foreach (array_intersect($fieldNames, $relationshipNames) as $name) {
            $issues[] = SchemaIssue::error("relationships.{$name}", 'name_clash', "[{$name}] is both a field and a relationship.");
        }

        return $issues;
    }
}
