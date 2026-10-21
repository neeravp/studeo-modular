<?php

namespace Studeo\Modular\Support\Schema;

use PhpToken;

/**
 * Reads the first constructor of a PHP file with the tokenizer: its parameters by name and type,
 * and which of them carry a #[Context] attribute. Both readers return null when the file has no
 * constructor, which the drift check reports as unverifiable.
 */
final class ConstructorReader
{
    /**
     * Parameter => its type as written ('' when untyped), in the order declared, from the first
     * constructor in the file; attributes, modifiers and defaults are not part of the type.
     *
     * @return array<string, string>|null
     */
    public function parameters(string $source): ?array
    {
        $parts = $this->constructorParts($source);
        $parameters = [];

        foreach ($parts ?? [] as $part) {
            $parameters = [...$parameters, ...$this->parameter($part)];
        }

        return $parts === null ? null : $parameters;
    }

    /**
     * The constructor parameters that carry a #[Context] attribute, by name.
     *
     * @return array<int, string>|null
     */
    public function contextParameters(string $source): ?array
    {
        $parts = $this->constructorParts($source);
        $withContext = array_filter($parts ?? [], $this->hasContextAttribute(...));
        $names = array_map(fn (array $part): ?string => array_key_first($this->parameter($part)), $withContext);

        return $parts === null ? null : array_values(array_filter($names, fn (int|string|null $name): bool => $name !== null));
    }

    /**
     * @param  array<int, PhpToken>  $part
     */
    private function hasContextAttribute(array $part): bool
    {
        return $this->isContextName($this->attributeName($part));
    }

    /**
     * @param  array<int, PhpToken>  $part
     */
    private function attributeName(array $part): ?PhpToken
    {
        $attribute = PhpTokens::find($part, fn (PhpToken $token): bool => $token->is(T_ATTRIBUTE));

        return $attribute === null ? null : ($part[$attribute + 1] ?? null);
    }

    private function isContextName(?PhpToken $name): bool
    {
        return $name !== null && ($name->text === 'Context' || str_ends_with($name->text, '\\Context'));
    }

    /**
     * @return array<int, array<int, PhpToken>>|null
     */
    private function constructorParts(string $source): ?array
    {
        $tokens = PhpTokens::tokens($source);
        $function = PhpTokens::findFunction($tokens, '__construct');

        return $this->opensParameterList($tokens, $function) ? PhpTokens::parts($tokens, $function + 2) : null;
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     * @return array<string, string>
     */
    private function parameter(array $tokens): array
    {
        $kept = $this->withoutModifiers($tokens);
        $variable = PhpTokens::find($kept, fn (PhpToken $token): bool => $token->is(T_VARIABLE));

        return $variable === null ? [] : [ltrim($kept[$variable]->text, '$') => PhpTokens::text(array_slice($kept, 0, $variable))];
    }

    /**
     * The tokens of a parameter without its attributes and its visibility and readonly modifiers.
     *
     * @param  array<int, PhpToken>  $tokens
     * @return array<int, PhpToken>
     */
    private function withoutModifiers(array $tokens): array
    {
        $depths = [];
        $inAttribute = 0;

        foreach ($tokens as $index => $token) {
            $depths[$index] = $inAttribute;
            $inAttribute = $token->is(T_ATTRIBUTE) ? 1 : ($inAttribute > 0 ? $inAttribute + PhpTokens::nesting($token) : 0);
        }

        return array_values(array_filter($tokens, fn (PhpToken $token, int $index): bool => ! $this->isModifier($token, $depths[$index]), ARRAY_FILTER_USE_BOTH));
    }

    private function isModifier(PhpToken $token, int $inAttribute): bool
    {
        return $inAttribute > 0 || $token->is([T_ATTRIBUTE, T_PUBLIC, T_PROTECTED, T_PRIVATE, T_READONLY]);
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     */
    private function opensParameterList(array $tokens, ?int $function): bool
    {
        return $function !== null && ($tokens[$function + 2]->text ?? '') === '(';
    }
}
