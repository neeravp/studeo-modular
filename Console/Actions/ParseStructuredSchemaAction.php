<?php

namespace Studeo\Modular\Console\Actions;

use Studeo\Modular\DataObjects\RawField;
use Studeo\Modular\DataObjects\RawRelationship;
use Studeo\Modular\DataObjects\RawSchema;
use Studeo\Modular\DataObjects\SchemaDefinition;
use Studeo\Modular\Support\Schema\SchemaKeys;

/**
 * Reads structured configuration (decoded from JSON, YAML or a PHP array) into a RawSchema.
 * A map holding none of the top-level keys is taken to be the fields themselves.
 */
class ParseStructuredSchemaAction
{
    public function __construct(
        private readonly ParseStructuredFieldAction $fieldParser = new ParseStructuredFieldAction,
        private readonly ParseStructuredRelationshipAction $relationshipParser = new ParseStructuredRelationshipAction,
        private readonly BuildSchemaDefinitionAction $builder = new BuildSchemaDefinitionAction,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(array $data): SchemaDefinition
    {
        return $this->builder->schema($this->read($data));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function read(array $data): RawSchema
    {
        $hasKeys = count(array_intersect(SchemaKeys::ROOT, array_keys($data))) > 0;
        $fields = $hasKeys ? ($data['fields'] ?? []) : $data;
        $relationships = $data['relationships'] ?? $data['relations'] ?? [];
        $malformed = [...$this->shapeProblems('fields', $fields), ...$this->shapeProblems('relationships', $relationships)];

        return new RawSchema(
            name: $data['name'] ?? null,
            table: $data['table'] ?? null,
            fields: $this->fields(is_array($fields) ? $fields : []),
            relationships: $this->relationships(is_array($relationships) ? $relationships : []),
            unknownKeys: $hasKeys ? array_values(array_diff(array_map('strval', array_keys($data)), SchemaKeys::ROOT)) : [],
            malformed: $malformed,
        );
    }

    /**
     * @return array<int, string>
     */
    private function shapeProblems(string $section, mixed $value): array
    {
        return $this->isMap($value) ? [] : ["{$section} must be a map of names to definitions"];
    }

    /**
     * @param  array<array-key, mixed>  $raw
     * @return array<int, RawField>
     */
    private function fields(array $raw): array
    {
        return array_map(
            fn (string|int $name, mixed $config): RawField => $this->fieldParser->read((string) $name, $config),
            array_keys($raw),
            $raw,
        );
    }

    /**
     * @param  array<array-key, mixed>  $raw
     * @return array<int, RawRelationship>
     */
    private function relationships(array $raw): array
    {
        return array_map(
            fn (string|int $name, mixed $config): RawRelationship => $this->relationshipParser->read((string) $name, $config),
            array_keys($raw),
            $raw,
        );
    }

    private function isMap(mixed $value): bool
    {
        return is_array($value) && array_filter(array_keys($value), 'is_int') === [];
    }
}
