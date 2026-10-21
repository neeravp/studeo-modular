<?php

namespace Studeo\Modular\Console\Actions;

use Studeo\Modular\DataObjects\FieldDefinition;
use Studeo\Modular\DataObjects\RawField;
use Studeo\Modular\Support\Schema\CliFieldReader;
use Studeo\Modular\Support\Schema\SchemaKeys;

/**
 * Reads one field of a structured schema (a string shorthand or a config map) into a RawField.
 */
class ParseStructuredFieldAction
{
    public function __construct(
        private readonly CliFieldReader $fieldReader = new CliFieldReader,
        private readonly ParseStructuredModifiersAction $modifiers = new ParseStructuredModifiersAction,
        private readonly BuildSchemaDefinitionAction $builder = new BuildSchemaDefinitionAction,
    ) {}

    public function execute(string $name, mixed $config): FieldDefinition
    {
        return $this->builder->field($this->read($name, $config));
    }

    public function read(string $name, mixed $config): RawField
    {
        return match (true) {
            is_string($config) => $this->fieldReader->readField("{$name}:{$config}"),
            is_array($config) => $this->readConfig($name, $config),
            $config === null => new RawField($name),
            default => new RawField($name, malformed: ['a field is a string like "string:nullable" or a map of keys']),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function readConfig(string $name, array $config): RawField
    {
        [$type, $inner] = $this->fieldReader->splitType((string) ($config['type'] ?? 'string'));
        $isEnum = strtolower($type) === 'enum';

        return new RawField(
            name: $name,
            type: $type,
            typeArguments: $isEnum ? [] : ($inner === null ? $this->explicitArguments($config) : $this->inlineArguments($inner)),
            modifiers: $this->modifiers->tokens($config),
            enum: ($isEnum ? $inner : null) ?? (is_string($config['enum'] ?? null) ? $config['enum'] : null),
            legacyCases: $this->legacyCases($config),
            unknownKeys: array_values(array_diff(array_map('strval', array_keys($config)), SchemaKeys::FIELD)),
            defaultNumber: $this->numericDefault($config['default'] ?? null),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function legacyCases(array $config): ?string
    {
        $legacy = $config['cases'] ?? $config['enum_cases'] ?? null;

        return $legacy === null ? null : implode(',', (array) $legacy);
    }

    /**
     * @return array<int, string>
     */
    private function inlineArguments(string $inner): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $inner)), fn (string $argument): bool => $argument !== ''));
    }

    /**
     * Arguments given as keys: type_arguments, length, or precision/total_digits with scale/decimal_places.
     *
     * @param  array<string, mixed>  $config
     * @return array<int, string>
     */
    private function explicitArguments(array $config): array
    {
        return match (true) {
            is_array($config['type_arguments'] ?? null) => array_map('strval', $config['type_arguments']),
            isset($config['length']) => [(string) $config['length']],
            default => $this->digitArguments($config['total_digits'] ?? $config['precision'] ?? null, $config['decimal_places'] ?? $config['scale'] ?? null),
        };
    }

    /**
     * @return array<int, string>
     */
    private function digitArguments(mixed $precision, mixed $scale): array
    {
        return match (true) {
            $precision !== null && $scale !== null => [(string) $precision, (string) $scale],
            $precision !== null => [(string) $precision],
            default => [],
        };
    }

    private function numericDefault(mixed $default): int|float|null
    {
        return is_int($default) || is_float($default) ? $default : null;
    }
}
