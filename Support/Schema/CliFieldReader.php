<?php

namespace Studeo\Modular\Support\Schema;

use Studeo\Modular\DataObjects\RawField;

/**
 * Reads one field token of the compact CLI form, such as "price:decimal(8,2):nullable", into a RawField.
 */
final class CliFieldReader
{
    public function readField(string $token): RawField
    {
        $parts = $this->splitToken($token);
        $rawType = $this->extractTokenPart($parts, 1) ?? 'string';
        [$type, $inner] = $this->splitType($rawType);

        return new RawField(
            name: trim($parts[0]),
            type: $type,
            typeArguments: strtolower($type) === 'enum' ? [] : $this->arguments($inner),
            modifiers: $this->modifiers($parts),
            enum: strtolower($type) === 'enum' ? $inner : null,
        );
    }

    /**
     * "decimal(8,2)" is the type "decimal" with the inner text "8,2"; a type without a closed
     * pair of parentheses keeps its text as the type, so it is reported as unknown.
     *
     * @return array{0: string, 1: string|null}
     */
    public function splitType(string $rawType): array
    {
        return $this->hasClosedParentheses($rawType) ? [(string) strstr($rawType, '(', true), trim(substr(strstr($rawType, '(') ?: '', 1, -1))] : [$rawType, null];
    }

    /**
     * @return array<int, string>
     */
    private function splitToken(string $token): array
    {
        return preg_split('/:(?![^(]*\))/', $token) ?: [];
    }

    /**
     * @param  array<int, string>  $parts
     */
    private function extractTokenPart(array $parts, int $index): ?string
    {
        return isset($parts[$index]) && trim($parts[$index]) !== '' ? trim($parts[$index]) : null;
    }

    /**
     * @return array<int, string>
     */
    private function arguments(?string $inner): array
    {
        return $inner === null ? [] : array_values(array_filter(array_map('trim', explode(',', $inner)), fn (string $argument): bool => $argument !== ''));
    }

    /**
     * @param  array<int, string>  $parts
     * @return array<int, string>
     */
    private function modifiers(array $parts): array
    {
        return array_values(array_filter(array_map('trim', array_slice($parts, 2)), fn (string $modifier): bool => $modifier !== ''));
    }

    private function hasClosedParentheses(string $rawType): bool
    {
        return str_contains($rawType, '(') && str_ends_with($rawType, ')');
    }
}
