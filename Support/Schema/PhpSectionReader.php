<?php

namespace Studeo\Modular\Support\Schema;

use PhpToken;

/**
 * Reads the parts of a generated PHP file that a schema determines, with the tokenizer: the
 * model's $fillable and casts(), the factory's definition() keys and the create migration's
 * columns. Each reader returns null when its section is not there or is not plain literals,
 * which the drift check reports as unverifiable. Nothing else in the file is looked at.
 */
final class PhpSectionReader
{
    /**
     * @return array<int, string>|null
     */
    public function fillable(string $source): ?array
    {
        $tokens = PhpTokens::tokens($source);
        $items = $this->assignedItems($tokens, PhpTokens::findToken($tokens, T_VARIABLE, '$fillable'));

        if ($items === null) {
            return null;
        }

        $names = array_map(fn (array $item): ?string => PhpTokens::literal($item['value']), $items);

        return in_array(null, $names, true) ? null : $names;
    }

    /**
     * Field => the source text of its cast.
     *
     * @return array<string, string>|null
     */
    public function casts(string $source): ?array
    {
        return $this->map($this->returnedItems(PhpTokens::tokens($source), 'casts'), true);
    }

    /**
     * @return array<int, string>|null
     */
    public function factoryKeys(string $source): ?array
    {
        $map = $this->map($this->returnedItems(PhpTokens::tokens($source), 'definition'), false);

        return $map === null ? null : array_keys($map);
    }

    /**
     * Column => its statement, normalised. The id and timestamps the stub always declares are left out.
     *
     * @return array<string, string>|null
     */
    public function migrationColumns(string $source): ?array
    {
        $tokens = PhpTokens::tokens($source);
        $create = PhpTokens::findToken($tokens, T_STRING, 'Schema');

        return $this->isCreateCall($tokens, $create) ? $this->statements($tokens, $create) : null;
    }

    /**
     * Parameter => its type as written ('' when untyped), in the order declared, from the first
     * constructor in the file; attributes, modifiers and defaults are not part of the type.
     *
     * @return array<string, string>|null
     */
    public function constructorParameters(string $source): ?array
    {
        return (new ConstructorReader)->parameters($source);
    }

    /**
     * The constructor parameters that carry a #[Context] attribute, by name.
     *
     * @return array<int, string>|null
     */
    public function contextParameters(string $source): ?array
    {
        return (new ConstructorReader)->contextParameters($source);
    }

    /**
     * A statement with whitespace removed except one space after each comma, so that how it was
     * wrapped does not matter.
     */
    public function normalize(string $statement): string
    {
        $tokens = PhpTokens::tokens("<?php {$statement}");

        return implode('', array_map(fn (PhpToken $token): string => $token->text === ',' ? ', ' : $token->text, $tokens));
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     * @return array<int, array{key: array<int, PhpToken>|null, value: array<int, PhpToken>}>|null
     */
    private function returnedItems(array $tokens, string $method): ?array
    {
        $function = PhpTokens::findFunction($tokens, $method);

        $return = $function === null ? null : PhpTokens::find($tokens, fn (PhpToken $token): bool => $token->is(T_RETURN), $function);

        return $return === null ? null : PhpTokens::items($tokens, $return + 1);
    }

    /**
     * @param  array<int, array{key: array<int, PhpToken>|null, value: array<int, PhpToken>}>|null  $items
     * @return array<string, string>|null
     */
    private function map(?array $items, bool $withValues): ?array
    {
        $keys = array_map(fn (array $item): ?string => $item['key'] === null ? null : PhpTokens::literal($item['key']), $items ?? []);

        if ($items === null) {
            return null;
        }

        if (in_array(null, $keys, true)) {
            return null;
        }

        return array_combine($keys, array_map(fn (array $item): string => $withValues ? PhpTokens::text($item['value']) : '', $items));
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     * @return array<string, string>
     */
    private function statements(array $tokens, int $from): array
    {
        $starts = $this->statementStarts($tokens, $from);

        return array_replace([], ...array_map(fn (int $start): array => $this->column($this->statementFrom($tokens, $start)), $starts));
    }

    /**
     * The `$table->` calls that begin a statement: one that sits inside the statement of an
     * earlier call (before its semicolon) does not.
     *
     * @param  array<int, PhpToken>  $tokens
     * @return array<int, int>
     */
    private function statementStarts(array $tokens, int $from): array
    {
        $calls = array_keys(array_filter(array_slice($tokens, $from, null, true), fn (PhpToken $token, int $index): bool => $this->startsTableCall($tokens, $index), ARRAY_FILTER_USE_BOTH));

        return array_values(array_filter($calls, fn (int $call, int $position): bool => $this->beginsStatement($tokens, $calls, $position), ARRAY_FILTER_USE_BOTH));
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     * @param  array<int, int>  $calls
     */
    private function beginsStatement(array $tokens, array $calls, int $position): bool
    {
        return $position === 0 || $this->hasSemicolonBetween($tokens, $calls[$position - 1], $calls[$position]);
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     */
    private function hasSemicolonBetween(array $tokens, int $first, int $second): bool
    {
        return (PhpTokens::find($tokens, fn (PhpToken $token): bool => $token->text === ';', $first) ?? PHP_INT_MAX) < $second;
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     */
    private function statementFrom(array $tokens, int $start): string
    {
        $end = PhpTokens::find($tokens, fn (PhpToken $token): bool => $token->text === ';', $start) ?? count($tokens) - 1;

        return $this->normalize(PhpTokens::text(array_slice($tokens, $start, $end - $start + 1)));
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     */
    private function startsTableCall(array $tokens, int $index): bool
    {
        return $tokens[$index]->is(T_VARIABLE) && $tokens[$index]->text === '$table' && ($tokens[$index + 1]->text ?? '') === '->';
    }

    /**
     * @return array<string, string>
     */
    private function column(string $statement): array
    {
        return in_array($statement, ['$table->id();', '$table->timestamps();'], true) || preg_match("/^\\\$table->\\w+\\('([^']*)'/", $statement, $match) !== 1 ? [] : [$match[1] => $statement];
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     *
     * @phpstan-assert-if-true int $at
     */
    private function isCreateCall(array $tokens, ?int $at): bool
    {
        return $at !== null && ($tokens[$at + 1]->text ?? '') === '::' && ($tokens[$at + 2]->text ?? '') === 'create';
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     * @return array<int, array{key: array<int, PhpToken>|null, value: array<int, PhpToken>}>|null
     */
    private function assignedItems(array $tokens, ?int $at): ?array
    {
        return $at !== null && ($tokens[$at + 1]->text ?? '') === '=' ? PhpTokens::items($tokens, $at + 2) : null;
    }
}
