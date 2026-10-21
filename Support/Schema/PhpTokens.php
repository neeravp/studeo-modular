<?php

namespace Studeo\Modular\Support\Schema;

use PhpToken;

/**
 * Token helpers for reading generated PHP: the significant tokens of a source, and the items of an
 * array literal split at its top level.
 */
final class PhpTokens
{
    /**
     * @return array<int, PhpToken>
     */
    public static function tokens(string $source): array
    {
        return array_values(array_filter(PhpToken::tokenize($source), fn (PhpToken $token): bool => ! $token->isIgnorable() && ! $token->is(T_OPEN_TAG)));
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     */
    public static function find(array $tokens, callable $matches, int $from = 0): ?int
    {
        $found = array_keys(array_filter(array_slice($tokens, $from, null, true), $matches));

        return $found[0] ?? null;
    }

    /**
     * The index of the first token with the given id and text.
     *
     * @param  array<int, PhpToken>  $tokens
     */
    public static function findToken(array $tokens, int $id, string $text): ?int
    {
        return self::find($tokens, fn (PhpToken $token): bool => $token->is($id) && $token->text === $text);
    }

    /**
     * The index of the first `function` keyword that is followed by the given name.
     *
     * @param  array<int, PhpToken>  $tokens
     */
    public static function findFunction(array $tokens, string $name): ?int
    {
        $found = array_keys(array_filter($tokens, fn (PhpToken $token, int $index): bool => self::isFunctionNamed($tokens, $index, $name), ARRAY_FILTER_USE_BOTH));

        return $found[0] ?? null;
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     */
    public static function text(array $tokens): string
    {
        return implode('', array_map(fn (PhpToken $token): string => $token->text, $tokens));
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     */
    private static function isFunctionNamed(array $tokens, int $index, string $name): bool
    {
        return $tokens[$index]->is(T_FUNCTION) && ($tokens[$index + 1]->text ?? '') === $name;
    }

    /**
     * The items of the array literal that opens at $open: each with its key tokens (or null) and value tokens.
     *
     * @param  array<int, PhpToken>  $tokens
     * @return array<int, array{key: array<int, PhpToken>|null, value: array<int, PhpToken>}>|null
     */
    public static function items(array $tokens, int $open): ?array
    {
        $close = self::closingIndex($tokens, $open);

        return $close === null ? null : self::split(array_slice($tokens, $open + 1, $close - $open - 1));
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     */
    public static function closingIndex(array $tokens, int $open): ?int
    {
        if (! in_array($tokens[$open]->text ?? '', ['[', '('], true)) {
            return null;
        }

        $closed = array_keys(array_filter(self::depths($tokens, $open), fn (int $depth): bool => $depth === 0));

        return $closed[0] ?? null;
    }

    /**
     * @param  array<int, PhpToken>  $inner  the tokens between the brackets
     * @return array<int, array{key: array<int, PhpToken>|null, value: array<int, PhpToken>}>
     */
    public static function split(array $inner): array
    {
        return array_map(self::item(...), self::arguments($inner));
    }

    /**
     * The tokens of each top-level comma-separated part of $inner, a trailing comma ignored.
     *
     * @param  array<int, PhpToken>  $inner
     * @return array<int, array<int, PhpToken>>
     */
    public static function arguments(array $inner): array
    {
        $inner = array_values($inner);
        $depths = self::depths($inner, 0);
        $commas = array_keys(array_filter($inner, fn (PhpToken $token, int $index): bool => self::isTopLevelComma($token, $depths[$index]), ARRAY_FILTER_USE_BOTH));
        $starts = [0, ...array_map(fn (int $comma): int => $comma + 1, $commas)];
        $ends = [...$commas, count($inner)];
        $parts = array_map(fn (int $start, int $end): array => array_slice($inner, $start, $end - $start), $starts, $ends);

        return $parts[array_key_last($parts)] === [] ? array_slice($parts, 0, -1) : $parts;
    }

    /**
     * The nesting depth after each token from $from on, keyed by the token's index.
     *
     * @param  array<int, PhpToken>  $tokens
     * @return array<int, int>
     */
    private static function depths(array $tokens, int $from): array
    {
        $depths = [];
        $depth = 0;

        foreach (array_slice($tokens, $from, null, true) as $index => $token) {
            $depth += self::nesting($token);
            $depths[$index] = $depth;
        }

        return $depths;
    }

    private static function isTopLevelComma(PhpToken $token, int $depth): bool
    {
        return $depth === 0 && $token->text === ',';
    }

    /**
     * The parts of the parenthesis or bracket that opens at $open, or null when it never closes.
     *
     * @param  array<int, PhpToken>  $tokens
     * @return array<int, array<int, PhpToken>>|null
     */
    public static function parts(array $tokens, int $open): ?array
    {
        $close = self::closingIndex($tokens, $open);

        return $close === null ? null : self::arguments(array_slice($tokens, $open + 1, $close - $open - 1));
    }

    public static function nesting(PhpToken $token): int
    {
        return match ($token->text) {
            '[', '(', '{', '#[' => 1,
            ']', ')', '}' => -1,
            default => 0,
        };
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     * @return array{key: array<int, PhpToken>|null, value: array<int, PhpToken>}
     */
    public static function item(array $tokens): array
    {
        $arrow = self::find($tokens, fn (PhpToken $token): bool => $token->is(T_DOUBLE_ARROW));

        return $arrow === null
            ? ['key' => null, 'value' => $tokens]
            : ['key' => array_slice($tokens, 0, $arrow), 'value' => array_slice($tokens, $arrow + 1)];
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     */
    public static function literal(array $tokens): ?string
    {
        return count($tokens) === 1 && $tokens[0]->is(T_CONSTANT_ENCAPSED_STRING) ? substr($tokens[0]->text, 1, -1) : null;
    }
}
