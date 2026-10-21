<?php

namespace Studeo\Modular\Support;

/**
 * Prints a field's rules as the PHP of one array entry: string rules quoted,
 * RuleExpression rules as code.
 */
final class RuleFormatter
{
    /**
     * @param  array<int, string|RuleExpression>  $rules
     */
    public static function line(string $field, array $rules, string $indent = '            '): string
    {
        $formatted = implode(', ', array_map(
            fn (string|RuleExpression $rule): string => $rule instanceof RuleExpression ? $rule->code : "'".addcslashes($rule, "'\\")."'",
            $rules,
        ));

        return "{$indent}'{$field}' => [{$formatted}],";
    }
}
