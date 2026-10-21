<?php

namespace Studeo\Modular\Support\Schema;

/**
 * How a Create* Action's precondition guards are called from its transaction: one by one, or, when
 * there are too many for one body, through ensureUniqueValues() and ensureReferencesExist(), each
 * calling the guards of its kind in field order. The guard bodies carry the {{ input }} placeholder.
 */
final class GuardGroups
{
    private const UNIQUE = 'ensureUniqueValues';

    private const REFERENCES = 'ensureReferencesExist';

    /**
     * @param  array<int, array{name: string, kind: string}>  $checks
     */
    public static function calls(array $checks, bool $grouped): string
    {
        return self::lines($grouped ? self::present($checks) : array_column($checks, 'name'), 12);
    }

    /**
     * @param  array<int, array{name: string, kind: string}>  $checks
     */
    public static function methods(array $checks, bool $grouped): string
    {
        if (! $grouped) {
            return '';
        }

        return self::method(self::UNIQUE, self::named($checks, 'unique')).self::method(self::REFERENCES, self::named($checks, 'foreign key'));
    }

    /**
     * @param  array<int, array{name: string, kind: string}>  $checks
     * @return array<int, string>
     */
    private static function present(array $checks): array
    {
        $kinds = array_column($checks, 'kind');

        return [...in_array('unique', $kinds, true) ? [self::UNIQUE] : [], ...in_array('foreign key', $kinds, true) ? [self::REFERENCES] : []];
    }

    /**
     * @param  array<int, array{name: string, kind: string}>  $checks
     * @return array<int, string>
     */
    private static function named(array $checks, string $kind): array
    {
        return array_column(array_filter($checks, fn (array $check): bool => $check['kind'] === $kind), 'name');
    }

    /**
     * @param  array<int, string>  $names
     */
    private static function method(string $group, array $names): string
    {
        return $names === [] ? '' : "\n\n    private function {$group}({{ input }} \$input): void\n    {\n".self::lines($names, 8).'    }';
    }

    /**
     * @param  array<int, string>  $names
     */
    private static function lines(array $names, int $indent): string
    {
        return implode('', array_map(fn (string $name): string => str_repeat(' ', $indent)."\$this->{$name}(\$input);\n", $names));
    }
}
