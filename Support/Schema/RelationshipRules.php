<?php

namespace Studeo\Modular\Support\Schema;

use Illuminate\Support\Str;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\RawRelationship;
use Studeo\Modular\DataObjects\SchemaIssue;

/**
 * The rules for one relationship. Only the types whose generated method is complete are valid;
 * the through and morph families need arguments (a through model, a morph name) the schema
 * cannot express, so they are rejected, not generated half-formed.
 */
final class RelationshipRules
{
    public const array TYPES = ['belongsTo', 'hasOne', 'hasMany', 'belongsToMany'];

    public const array UNSUPPORTED = [
        'hasThrough', 'hasManyThrough', 'hasOneThrough', 'morphTo', 'morphOne', 'morphMany', 'morphToMany',
    ];

    private const string CLASS_NAME = '/^\\\\?[A-Z][A-Za-z0-9]*(\\\\[A-Z][A-Za-z0-9]*)*$/';

    /**
     * @param  array<int, string>  $fieldNames  the schema's field names, for the foreign key check
     * @return array<int, SchemaIssue>
     */
    public function check(RawRelationship $relationship, ?ModuleDefinition $module, array $fieldNames): array
    {
        $path = "relationships.{$relationship->name}";
        $type = $this->canonicalType($relationship->type);

        return [
            ...$this->shape($relationship, $path),
            ...$this->name($relationship, $path),
            ...$this->type($relationship, $path, $type),
            ...$this->model($relationship, $path, $module),
            ...$this->keys($relationship, $path, $type, $fieldNames),
        ];
    }

    private function canonicalType(string $type): ?string
    {
        $matches = array_filter(self::TYPES, fn (string $known): bool => strtolower($known) === strtolower($type));

        return array_shift($matches);
    }

    /**
     * @return array<int, SchemaIssue>
     */
    private function shape(RawRelationship $relationship, string $path): array
    {
        return [
            ...array_map(fn (string $problem): SchemaIssue => SchemaIssue::error($path, 'relationship_shape', "Relationship [{$relationship->name}]: {$problem}."), $relationship->malformed),
            ...($relationship->unknownKeys === [] ? [] : [SchemaIssue::error($path, 'relationship_key', 'Unknown key(s) ['.implode(', ', $relationship->unknownKeys)."] on relationship [{$relationship->name}].", SchemaKeys::RELATIONSHIP)]),
        ];
    }

    /**
     * @return array<int, SchemaIssue>
     */
    private function name(RawRelationship $relationship, string $path): array
    {
        return preg_match('/^[a-z][A-Za-z0-9_]*$/', $relationship->name) === 1
            ? []
            : [SchemaIssue::error($path, 'relationship_name', "Relationship name [{$relationship->name}] must start with a lower-case letter, e.g. author.")];
    }

    /**
     * @return array<int, SchemaIssue>
     */
    private function type(RawRelationship $relationship, string $path, ?string $type): array
    {
        $unsupported = in_array(strtolower($relationship->type), array_map('strtolower', self::UNSUPPORTED), true);

        return match (true) {
            $type !== null => [],
            $unsupported => [SchemaIssue::error($path, 'relationship_type', "Relationship [{$relationship->name}]: type [{$relationship->type}] is not supported, because it needs arguments a schema cannot state.", self::TYPES)],
            default => [SchemaIssue::error($path, 'relationship_type', "Relationship [{$relationship->name}] has an unknown type [{$relationship->type}].", self::TYPES)],
        };
    }

    /**
     * @return array<int, SchemaIssue>
     */
    private function model(RawRelationship $relationship, string $path, ?ModuleDefinition $module): array
    {
        $model = $relationship->relatedModel ?? Str::studly(Str::singular($relationship->name));

        if (preg_match(self::CLASS_NAME, $model) !== 1) {
            return [SchemaIssue::error($path, 'relationship_model', "Relationship [{$relationship->name}]: [{$model}] is not a class name, e.g. User.")];
        }

        if ($this->needsNoTargetCheck($module, $model)) {
            return [];
        }

        $models = ModuleModels::names($module);
        $known = $models === [] ? 'The module has no models yet.' : "The module's models: ".implode(', ', $models).'.';

        return in_array($model, $models, true)
            ? []
            : [SchemaIssue::error($path, 'relationship_target', "Relationship [{$relationship->name}]: model [{$model}] is not a model of module [{$module->name}]; a relationship can only point at one of them, or at User, the application's user. {$known}")];
    }

    /**
     * @param  array<int, string>  $fieldNames
     * @return array<int, SchemaIssue>
     */
    private function keys(RawRelationship $relationship, string $path, ?string $type, array $fieldNames): array
    {
        /** @var array<int, string> $given */
        $given = array_filter([$relationship->foreignKey, $relationship->ownerKey], fn (?string $key): bool => (string) $key !== '');
        $badKey = array_filter($given, fn (string $key): bool => preg_match('/^[a-z][a-z0-9_]*$/', $key) !== 1);

        return match (true) {
            $badKey !== [] => [SchemaIssue::error($path, 'relationship_key_name', "Relationship [{$relationship->name}]: keys must be lower snake case column names.")],
            $this->takesNoKeys($type, $given) => [SchemaIssue::error($path, 'relationship_key_name', "Relationship [{$relationship->name}]: belongsToMany does not take foreign_key or owner_key; its pivot table is named by convention.")],
            $this->lacksForeignKeyField($relationship, $type, $fieldNames) => [SchemaIssue::warning($path, 'relationship_foreign_key', "Relationship [{$relationship->name}]: no field declares its foreign key [".$this->foreignKeyColumn($relationship).'].')],
            default => [],
        };
    }

    /**
     * @phpstan-assert-if-false ModuleDefinition $module
     */
    private function needsNoTargetCheck(?ModuleDefinition $module, string $model): bool
    {
        return $module === null || $model === 'User';
    }

    /**
     * @param  array<int, string>  $given
     */
    private function takesNoKeys(?string $type, array $given): bool
    {
        return $type === 'belongsToMany' && $given !== [];
    }

    /**
     * @param  array<int, string>  $fieldNames
     */
    private function lacksForeignKeyField(RawRelationship $relationship, ?string $type, array $fieldNames): bool
    {
        return $type === 'belongsTo' && ! in_array($this->foreignKeyColumn($relationship), $fieldNames, true);
    }

    private function foreignKeyColumn(RawRelationship $relationship): string
    {
        return $relationship->foreignKey ?? Str::snake($relationship->name).'_id';
    }
}
