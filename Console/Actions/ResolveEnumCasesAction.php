<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * Turns a comma-separated list of backed values into case name => value. A value is lower snake
 * case and at most 30 characters, because a schema stores it in a string(x, 30) column; its case
 * name is the studly form of the value.
 */
final class ResolveEnumCasesAction
{
    public const int MAX_LENGTH = 30;

    public const string EXAMPLE = '--cases=draft,published';

    /**
     * @return array<string, string>
     *
     * @throws RuntimeException naming the first problem and the valid form
     */
    public function execute(string $cases): array
    {
        $values = array_values(array_filter(array_map('trim', explode(',', $cases)), fn (string $value): bool => $value !== ''));
        $resolved = [];

        foreach ($values as $value) {
            $resolved = $this->withCase($resolved, $value, $values);
        }

        return $resolved === [] ? throw $this->fail('The list has no values') : $resolved;
    }

    /**
     * @param  array<string, string>  $resolved
     * @param  array<int, string>  $values
     * @return array<string, string>
     */
    private function withCase(array $resolved, string $value, array $values): array
    {
        $this->checkValue($value, $values);
        $name = Str::studly($value);

        if (isset($resolved[$name])) {
            throw $this->fail("Values [{$resolved[$name]}] and [{$value}] give the same case name [{$name}]");
        }

        $resolved[$name] = $value;

        return $resolved;
    }

    /**
     * @param  array<int, string>  $values
     */
    private function checkValue(string $value, array $values): void
    {
        match (true) {
            preg_match('/^[a-z][a-z0-9_]*$/', $value) !== 1 => throw $this->fail("Value [{$value}] is not lower snake case"),
            strlen($value) > self::MAX_LENGTH => throw $this->fail("Value [{$value}] is longer than ".self::MAX_LENGTH.' characters; the value must be at most '.self::MAX_LENGTH),
            count(array_keys($values, $value, true)) > 1 => throw $this->fail("Value [{$value}] is given twice"),
            strtolower(Str::studly($value)) === 'class' => throw $this->fail("Value [{$value}] would give a case named Class, which PHP reserves"),
            default => null,
        };
    }

    public function forms(): string
    {
        return 'Valid: lower snake case values, each starting with a letter and at most '.self::MAX_LENGTH.' characters, separated by commas, e.g. '.self::EXAMPLE.' or --cases=draft,in_review,published.';
    }

    private function fail(string $problem): RuntimeException
    {
        return new RuntimeException("{$problem}. {$this->forms()}");
    }
}
