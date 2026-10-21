<?php

namespace Studeo\Modular\Console\Actions;

use Studeo\Modular\Support\Schema\SchemaKeys;

/**
 * Turns the modifier keys of a structured field config into modifier tokens, one boolean key
 * per modifier: nullable, unique, index, primary, unsigned, hashed, guarded, context, fillable,
 * constrained, cascade_on_delete (or cascade), null_on_delete, default, cast, and a modifiers list.
 */
class ParseStructuredModifiersAction
{
    /**
     * @param  array<string, mixed>  $config
     * @return array<int, string>
     */
    public function tokens(array $config): array
    {
        return array_values(array_unique([
            ...$this->flags($config, ['nullable', 'unique', 'index', 'primary', 'unsigned']),
            ...$this->constrained($config),
            ...(empty($config['cascade_on_delete']) && empty($config['cascade']) ? [] : ['cascadeOnDelete']),
            ...(empty($config['null_on_delete']) ? [] : ['nullOnDelete']),
            ...(array_key_exists('default', $config) ? ['default('.$this->formatDefaultValue($config['default']).')'] : []),
            ...$this->flags($config, ['hashed', 'guarded', 'context']),
            ...$this->fillable($config),
            ...(isset($config['cast']) && is_string($config['cast']) ? ["cast({$config['cast']})"] : []),
            ...$this->explicit($config),
        ]));
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<int, string>  $names
     * @return array<int, string>
     */
    private function flags(array $config, array $names): array
    {
        return array_values(array_filter($names, fn (string $name): bool => ! empty($config[$name]) && in_array($name, SchemaKeys::FLAGS, true)));
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, string>
     */
    private function constrained(array $config): array
    {
        $target = $config['constrained'] ?? null;

        return match (true) {
            empty($target) => [],
            is_string($target) => ["constrained({$target})"],
            default => ['constrained'],
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, string>
     */
    private function fillable(array $config): array
    {
        return array_key_exists('fillable', $config) ? [$config['fillable'] ? 'fillable' : 'fillable(false)'] : [];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, string>
     */
    private function explicit(array $config): array
    {
        $modifiers = $config['modifiers'] ?? [];

        return is_array($modifiers) ? array_values(array_map(fn (mixed $modifier): string => is_string($modifier) ? $modifier : '?', $modifiers)) : [];
    }

    private function formatDefaultValue(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_numeric($value) => (string) $value,
            default => "'".addcslashes((string) $value, "'\\")."'",
        };
    }
}
