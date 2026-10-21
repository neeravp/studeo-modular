<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Support\Str;
use RuntimeException;
use Studeo\Check\Rules\StepCount;
use Studeo\Modular\DataObjects\FieldDefinition;
use Studeo\Modular\DataObjects\SchemaDefinition;
use Studeo\Modular\Support\Schema\ForeignKeyTarget;
use Studeo\Modular\Support\Schema\GuardGroups;

/**
 * The checks a Create* Action runs inside its transaction, from a schema: a unique field must
 * not be taken, a foreign key must point at a row. Each check is a private guard method on the
 * Action, ensure{Field}IsUnique() or ensure{Field}Exists(), that takes the Input, and the
 * transaction only calls them in field order. Above THRESHOLD checks (StepCount's default) the
 * transaction calls two group methods instead, ensureUniqueValues() and ensureReferencesExist(),
 * which call the guards of their kind in field order; more than THRESHOLD of one kind is refused. They use DB::table() only, so they
 * name no model class, and a nullable field returns early when it has no value. The guard bodies carry the
 * {{ input }} placeholder for the Input's imported name. A field the Input does not have is
 * skipped with a notice. The unique index in the database stays the real guard: a concurrent
 * insert can pass the check and fail on the index.
 */
final class BuildActionPreconditionsAction
{
    public const THRESHOLD = StepCount::DEFAULT_MAX;

    /**
     * @param  array<int, string>|null  $inputProperties  the Input's property names, or null for every field
     * @return array{checks: string, guards: string, notices: array<int, string>}
     *
     * @throws RuntimeException
     */
    public function execute(SchemaDefinition $schema, string $table, ?array $inputProperties = null): array
    {
        [$checks, $notices] = $this->collect($schema, $inputProperties);
        $this->refuseOverLimit($checks);

        return [...$this->render($checks, $table), 'notices' => $notices];
    }

    /**
     * @param  array<int, array{name: string, kind: string, field: FieldDefinition, property: string}>  $checks
     * @return array{checks: string, guards: string}
     */
    private function render(array $checks, string $table): array
    {
        $grouped = $this->isGrouped($checks);

        return ['checks' => GuardGroups::calls($checks, $grouped), 'guards' => GuardGroups::methods($checks, $grouped).$this->guards($checks, $table)];
    }

    /**
     * @param  array<int, array{name: string, kind: string, field: FieldDefinition, property: string}>  $checks
     */
    private function guards(array $checks, string $table): string
    {
        return implode('', array_map(fn (array $check): string => $this->guard($check['name'], $check['kind'], $check['field'], $check['property'], $table), $checks));
    }

    /**
     * @param  array<int, array{name: string}>  $checks
     */
    private function isGrouped(array $checks): bool
    {
        return count($checks) > self::THRESHOLD;
    }

    /**
     * Refuses a schema whose checks of one kind could not fit a guard group, before anything is written.
     *
     * @param  array<int, string>|null  $inputProperties
     *
     * @throws RuntimeException
     */
    public function ensureWithinLimit(SchemaDefinition $schema, string $actionName, ?array $inputProperties = null): void
    {
        if (! $this->applies($actionName)) {
            return;
        }

        $this->refuseOverLimit($this->collect($schema, $inputProperties)[0]);
    }

    /**
     * @param  array<int, string>|null  $inputProperties
     * @return array{0: array<int, array{name: string, kind: string, field: FieldDefinition, property: string}>, 1: array<int, string>}
     */
    private function collect(SchemaDefinition $schema, ?array $inputProperties): array
    {
        $checks = [];
        $notices = [];

        foreach ($schema->fields as $field) {
            $kinds = array_keys(array_filter(['unique' => $field->isUnique(), 'foreign key' => ForeignKeyTarget::isForeignKey($field)]));
            $skipped = $this->isSkipped($field, $inputProperties);

            $notices = [...$notices, ...($skipped ? $this->skipNotices($field, $kinds) : [])];
            $checks = [...$checks, ...($skipped ? [] : $this->fieldChecks($field, $kinds))];
        }

        return [$checks, $notices];
    }

    /**
     * @param  array<int, string>|null  $inputProperties
     */
    private function isSkipped(FieldDefinition $field, ?array $inputProperties): bool
    {
        return $inputProperties !== null && ! in_array($this->property($field), $inputProperties, true);
    }

    private function property(FieldDefinition $field): string
    {
        return lcfirst(str($field->name)->studly()->toString());
    }

    /**
     * @param  array<int, string>  $kinds
     * @return array<int, string>
     */
    private function skipNotices(FieldDefinition $field, array $kinds): array
    {
        return array_map(fn (string $kind): string => "Skipped the {$kind} check for [{$field->name}]: the existing Input has no property [{$this->property($field)}].", $kinds);
    }

    /**
     * @param  array<int, string>  $kinds
     * @return array<int, array{name: string, kind: string, field: FieldDefinition, property: string}>
     */
    private function fieldChecks(FieldDefinition $field, array $kinds): array
    {
        return array_map(fn (string $kind): array => ['name' => 'ensure'.str($field->name)->studly().($kind === 'unique' ? 'IsUnique' : 'Exists'), 'kind' => $kind, 'field' => $field, 'property' => $this->property($field)], $kinds);
    }

    /**
     * @param  array<int, array{kind: string}>  $checks
     *
     * @throws RuntimeException
     */
    private function refuseOverLimit(array $checks): void
    {
        $over = array_filter(array_count_values(array_column($checks, 'kind')), fn (int $count): bool => $count > self::THRESHOLD);

        if ($over === []) {
            return;
        }

        $kind = (string) array_key_first($over);

        throw new RuntimeException("The schema has {$over[$kind]} {$kind} checks, and a Create* Action holds at most ".self::THRESHOLD.' of one kind. Split the schema or write the checks by hand; nothing was written.');
    }

    private function guard(string $name, string $kind, FieldDefinition $field, string $property, string $table): string
    {
        $column = $field->name;
        $value = "\$input->{$property}";
        $early = $field->isNullable() ? "        if ({$value} === null) {\n            return;\n        }\n\n" : '';
        $human = str_replace('_', ' ', $column);

        [$condition, $message] = $kind === 'unique'
            ? ["DB::table('{$table}')->where('{$column}', {$value})->exists()", "The {$human} has already been taken."]
            : ["! DB::table('".ForeignKeyTarget::table($field)."')->where('id', {$value})->exists()", "The selected {$human} does not exist."];

        return "\n\n    private function {$name}({{ input }} \$input): void\n    {\n{$early}"
            ."        if ({$condition}) {\n"
            ."            throw ValidationException::withMessages(['{$column}' => '{$message}']);\n"
            ."        }\n    }";
    }

    /**
     * The table a schema's checks run against: the schema's own, else the plural of what a Create*
     * Action creates (CreatePostAction creates posts).
     */
    public function table(SchemaDefinition $schema, string $actionName): string
    {
        $entity = Str::after(Str::beforeLast(class_basename($actionName), 'Action'), 'Create');

        return $schema->table ?? Str::snake(Str::plural($entity));
    }

    /**
     * Preconditions are generated for Create* Actions only; an update needs "ignore this record".
     */
    public function applies(string $actionName): bool
    {
        return preg_match('/^Create[A-Z]/', Str::beforeLast(class_basename($actionName), 'Action')) === 1;
    }
}
