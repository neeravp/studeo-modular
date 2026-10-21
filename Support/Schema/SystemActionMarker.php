<?php

namespace Studeo\Modular\Support\Schema;

use PhpToken;

/**
 * Whether an Action's source marks the class #[SystemAction], written with or without a namespace
 * in front of the name. Only the attributes before the class declaration count.
 */
final class SystemActionMarker
{
    public function isMarked(string $source): bool
    {
        $tokens = PhpToken::tokenize($source);
        $before = array_slice($tokens, 0, $this->classIndex($tokens), true);

        return array_filter(array_keys($before), fn (int $index): bool => $this->isSystemActionAttribute($tokens, $index)) !== [];
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     */
    private function classIndex(array $tokens): int
    {
        $classes = array_keys(array_filter($tokens, fn (PhpToken $token): bool => $token->id === T_CLASS));

        return $classes[0] ?? count($tokens);
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     */
    private function isSystemActionAttribute(array $tokens, int $index): bool
    {
        return $tokens[$index]->id === T_ATTRIBUTE && $this->namesSystemAction($tokens, $index);
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     */
    private function namesSystemAction(array $tokens, int $start): bool
    {
        $rest = array_slice($tokens, $start + 1);
        $end = array_keys(array_filter($rest, fn (PhpToken $token): bool => $token->text === ']'))[0] ?? count($rest);

        return array_filter(array_slice($rest, 0, $end), $this->isSystemActionName(...)) !== [];
    }

    private function isSystemActionName(PhpToken $token): bool
    {
        return $token->text === 'SystemAction' || str_ends_with($token->text, '\\SystemAction');
    }
}
