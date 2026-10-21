<?php

namespace Studeo\Modular\Support\Schema;

use Illuminate\Support\Str;
use Studeo\Modular\DataObjects\RawField;
use Studeo\Modular\DataObjects\RawRelationship;
use Studeo\Modular\DataObjects\RawSchema;
use Symfony\Component\Yaml\Yaml;

/**
 * Writes a schema as the YAML a schema file holds: table, then fields, then relationships, in the
 * order they were written. A field is the shorthand string (the CLI token after its name) wherever
 * that reads back as the same field, and a map otherwise.
 */
final class SchemaYamlWriter
{
    public function __construct(
        private readonly CliFieldReader $reader = new CliFieldReader,
    ) {}

    public function dump(RawSchema $schema, string $table, ?string $model = null): string
    {
        $data = $this->nameIsNotImplied($model, $table) ? ['name' => $model] : [];
        $data['table'] = $table;
        $data['fields'] = $this->fields($schema->fields);

        if ($schema->relationships !== []) {
            $data['relationships'] = $this->relationships($schema->relationships);
        }

        return Yaml::dump($data, 4, 2);
    }

    /**
     * @param  array<int, RawField>  $fields
     * @return array<string, string|array<string, mixed>>
     */
    private function fields(array $fields): array
    {
        $written = [];

        foreach ($fields as $field) {
            $shorthand = $this->shorthand($field);
            $written[$field->name] = $this->reader->readField("{$field->name}:{$shorthand}") == $field ? $shorthand : $this->map($field);
        }

        return $written === [] ? [] : $written;
    }

    private function shorthand(RawField $field): string
    {
        return $this->typeWithArguments($field).implode('', array_map(fn (string $modifier): string => ":{$modifier}", $field->modifiers));
    }

    private function typeWithArguments(RawField $field): string
    {
        $inner = $field->enum ?? ($field->typeArguments === [] ? null : implode(',', $field->typeArguments));

        return $inner === null ? $field->type : "{$field->type}({$inner})";
    }

    /**
     * @return array<string, mixed>
     */
    private function map(RawField $field): array
    {
        return ['type' => $this->typeWithArguments($field)] + ($field->modifiers === [] ? [] : ['modifiers' => $field->modifiers]);
    }

    /**
     * @param  array<int, RawRelationship>  $relationships
     * @return array<string, string|array<string, string>>
     */
    private function relationships(array $relationships): array
    {
        $written = [];

        foreach ($relationships as $relationship) {
            $written[$relationship->name] = $this->isPlain($relationship)
                ? $relationship->type.($relationship->relatedModel === null ? '' : "({$relationship->relatedModel})")
                : array_filter([
                    'type' => $relationship->type,
                    'model' => $relationship->relatedModel,
                    'foreign_key' => $relationship->foreignKey,
                    'owner_key' => $relationship->ownerKey,
                ], fn (?string $value): bool => $value !== null);
        }

        return $written;
    }

    private function nameIsNotImplied(?string $model, string $table): bool
    {
        return $model !== null && $model !== Str::studly(Str::singular($table));
    }

    private function isPlain(RawRelationship $relationship): bool
    {
        return $relationship->foreignKey === null && $relationship->ownerKey === null;
    }
}
