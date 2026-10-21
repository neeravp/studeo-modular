<?php

namespace Studeo\Modular\Support\Schema;

use InvalidArgumentException;
use Studeo\Modular\DataObjects\FieldDefault;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\RawField;
use Studeo\Modular\DataObjects\SchemaIssue;
use Studeo\Modular\Support\EnumFieldReference;

/**
 * The enum rules of a field. The reference itself is checked by EnumFieldReference; with a
 * module, the enum file must exist, be string-backed, fit the 30 character column, and contain
 * the default as a case value. The class is read from its file, never loaded.
 */
final class EnumRules
{
    public function __construct(
        private readonly EnumFileReader $reader = new EnumFileReader,
    ) {}

    /**
     * @return array<int, SchemaIssue>
     */
    public function check(RawField $field, string $path, ?string $canonical, ?FieldDefault $default, ?ModuleDefinition $module): array
    {
        if ($canonical !== 'enum') {
            return $this->hasEnumReference($field)
                ? [SchemaIssue::error($path, 'field_enum', "Field [{$field->name}]: an enum reference is only valid on a field of type enum.")]
                : [];
        }

        try {
            $name = $this->className($field);
        } catch (InvalidArgumentException $exception) {
            return [SchemaIssue::error($path, 'field_enum', $exception->getMessage())];
        }

        return $module === null ? [] : $this->inModule($name, $path, $default, $module);
    }

    private function className(RawField $field): string
    {
        return match (true) {
            $field->legacyCases !== null => throw EnumFieldReference::legacy($field->name, $field->legacyCases),
            $field->enum === null || $field->enum === '' => throw EnumFieldReference::missing($field->name),
            default => EnumFieldReference::classNameOrFail($field->name, $field->enum),
        };
    }

    /**
     * @return array<int, SchemaIssue>
     */
    private function inModule(string $name, string $path, ?FieldDefault $default, ModuleDefinition $module): array
    {
        $relative = "Enums/{$name}.php";
        $info = $this->reader->read($module->path.'/'.$relative);

        if ($this->isNotDeclared($info)) {
            $problem = $info === null ? "was not found at {$relative} in module [{$module->name}]" : "is not declared in {$relative}";

            return [SchemaIssue::error($path, 'enum_missing', "Enum [{$name}] {$problem}. Create it with ".EnumFieldReference::create($name).'.')];
        }

        return [...$this->backing($name, $path, $info), ...$this->defaultCase($name, $path, $info['cases'], $default)];
    }

    /**
     * @param  array{backing: string|null, cases: array<string, string|null>}  $info
     * @return array<int, SchemaIssue>
     */
    private function backing(string $name, string $path, array $info): array
    {
        return array_values(array_filter([
            $this->backingIssue($name, $path, $info),
            $this->lengthIssue($name, $path, $info),
            $this->unreadableIssue($name, $path, $info),
        ]));
    }

    /**
     * @param  array{backing: string|null, cases: array<string, string|null>}  $info
     */
    private function backingIssue(string $name, string $path, array $info): ?SchemaIssue
    {
        return $info['backing'] === 'string' ? null : SchemaIssue::error($path, 'enum_backing', "Enum [{$name}] must be string-backed, but is ".($info['backing'] ?? 'not backed').'.');
    }

    /**
     * @param  array{backing: string|null, cases: array<string, string|null>}  $info
     */
    private function lengthIssue(string $name, string $path, array $info): ?SchemaIssue
    {
        $tooLong = array_keys(array_filter($info['cases'], self::isTooLong(...)));

        return $tooLong === [] ? null : SchemaIssue::error($path, 'enum_value_length', "Enum [{$name}] has case values longer than 30 characters, the column length: ".implode(', ', $tooLong).'.');
    }

    /**
     * @param  array{backing: string|null, cases: array<string, string|null>}  $info
     */
    private function unreadableIssue(string $name, string $path, array $info): ?SchemaIssue
    {
        $unreadable = $info['backing'] === 'string' ? array_keys(array_filter($info['cases'], fn (?string $value): bool => $value === null)) : [];

        return $unreadable === [] ? null : SchemaIssue::warning($path, 'enum_unreadable', "Cannot read the value of case(s) of enum [{$name}] because it is not a plain literal: ".implode(', ', $unreadable).'.');
    }

    /**
     * @param  array<string, string|null>  $cases
     * @return array<int, SchemaIssue>
     */
    private function defaultCase(string $name, string $path, array $cases, ?FieldDefault $default): array
    {
        $values = array_values(array_filter($cases, fn (?string $value): bool => $value !== null));

        if ($this->isAcceptedDefault($default, $values)) {
            return [];
        }

        return in_array(null, $cases, true)
            ? [SchemaIssue::warning($path, 'enum_default_unverified', "Cannot verify default [{$default->value}] against enum [{$name}]: some case values are not plain literals.")]
            : [SchemaIssue::error($path, 'enum_default', "Default [{$default->value}] is not a case value of enum [{$name}].", $values)];
    }

    private function hasEnumReference(RawField $field): bool
    {
        return $field->enum !== null || $field->legacyCases !== null;
    }

    /**
     * @param  array{declared: bool}|null  $info
     */
    private function isNotDeclared(?array $info): bool
    {
        return $info === null || ! $info['declared'];
    }

    private static function isTooLong(?string $value): bool
    {
        return $value !== null && mb_strlen($value) > 30;
    }

    /**
     * No default, or a default that is one of the case values.
     *
     * @param  array<int, string>  $values
     *
     * @phpstan-assert-if-false FieldDefault $default
     */
    private function isAcceptedDefault(?FieldDefault $default, array $values): bool
    {
        return $default === null || $default->value === null || in_array((string) $default->value, $values, true);
    }
}
