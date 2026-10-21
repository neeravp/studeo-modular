<?php

namespace Studeo\Modular\Support\Schema;

use PhpToken;

/**
 * Reads a PHP enum's declaration straight from its file with the tokenizer, so the module's
 * classes need not be autoloadable. It finds the backing type and the cases at the top level of
 * the enum body (a switch or match inside a method is not a case).
 */
final class EnumFileReader
{
    /**
     * @return array{declared: bool, backing: string|null, cases: array<string, string|null>}|null null when there is no file
     */
    public function read(string $file): ?array
    {
        if (! is_file($file)) {
            return null;
        }

        $tokens = array_values(array_filter(
            PhpToken::tokenize((string) file_get_contents($file)),
            fn (PhpToken $token): bool => ! $token->isIgnorable(),
        ));

        $start = $this->indexOf($tokens, T_ENUM);

        return $start === null ? ['declared' => false, 'backing' => null, 'cases' => []] : [
            'declared' => true,
            'backing' => $this->backingType($tokens, $start),
            'cases' => $this->cases($tokens, $start),
        ];
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     */
    private function backingType(array $tokens, int $start): ?string
    {
        $colon = $tokens[$start + 2] ?? null;

        return $colon?->text === ':' ? strtolower($tokens[$start + 3]->text ?? '') : null;
    }

    /**
     * Case name => literal value, or null when the case has no value or one that is not a plain literal.
     *
     * @param  array<int, PhpToken>  $tokens
     * @return array<string, string|null>
     */
    private function cases(array $tokens, int $start): array
    {
        $depths = $this->depthsFrom($tokens, $start);
        $end = $this->bodyEnd($tokens, $depths, $start);
        $indexes = array_filter(array_keys($depths), fn (int $index): bool => $this->isTopLevelCase($tokens, $depths, $index, $end));
        $cases = [];

        foreach ($indexes as $index) {
            $cases[$tokens[$index + 1]->text] = $this->literalValue($tokens, $index + 2);
        }

        return $cases;
    }

    /**
     * The brace depth after each token from the start on.
     *
     * @param  array<int, PhpToken>  $tokens
     * @return array<int, int>
     */
    private function depthsFrom(array $tokens, int $start): array
    {
        $depths = [];
        $depth = 0;

        foreach (array_slice($tokens, $start, null, true) as $index => $token) {
            $depth += $this->braceChange($token);
            $depths[$index] = $depth;
        }

        return $depths;
    }

    private function braceChange(PhpToken $token): int
    {
        return match ($token->text) {
            '{' => 1,
            '}' => -1,
            default => 0,
        };
    }

    /**
     * The index of the brace that closes the enum body, or the token count when there is none.
     *
     * @param  array<int, PhpToken>  $tokens
     * @param  array<int, int>  $depths
     */
    private function bodyEnd(array $tokens, array $depths, int $start): int
    {
        $closers = array_filter(array_keys($depths), fn (int $index): bool => $this->closesBody($tokens, $depths, $index, $start));

        return array_shift($closers) ?? count($tokens);
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     * @param  array<int, int>  $depths
     */
    private function closesBody(array $tokens, array $depths, int $index, int $start): bool
    {
        return $index > $start && $depths[$index] === 0 && $tokens[$index]->text === '}';
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     * @param  array<int, int>  $depths
     */
    private function isTopLevelCase(array $tokens, array $depths, int $index, int $end): bool
    {
        return $index < $end && $depths[$index] === 1 && $tokens[$index]->is(T_CASE);
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     */
    private function literalValue(array $tokens, int $at): ?string
    {
        return $this->isPlainLiteral($tokens, $at) ? $this->unquote($tokens[$at + 1]->text) : null;
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     */
    private function isPlainLiteral(array $tokens, int $at): bool
    {
        return ($tokens[$at]->text ?? '') === '='
            && isset($tokens[$at + 1], $tokens[$at + 2])
            && $tokens[$at + 1]->is([T_CONSTANT_ENCAPSED_STRING, T_LNUMBER])
            && $tokens[$at + 2]->text === ';';
    }

    private function unquote(string $literal): string
    {
        return preg_match('/^([\'"])(.*)\1$/s', $literal, $match) === 1 ? stripcslashes($match[2]) : $literal;
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     */
    private function indexOf(array $tokens, int $id): ?int
    {
        $found = array_keys(array_filter($tokens, fn (PhpToken $token): bool => $token->is($id)));

        return $found[0] ?? null;
    }
}
