<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Support\Str;
use RuntimeException;
use Studeo\Modular\Console\Generation\NameSanitizer;

/**
 * Normalises an action name and appends Action only when the last segment does not
 * already end in it. Also names what pairs with an action: its Input and its ability.
 */
final class ResolveActionNameAction
{
    private const SUFFIX = 'Action';

    public function __construct(
        private readonly NameSanitizer $sanitizer,
    ) {}

    public function execute(string $name): string
    {
        $segments = explode('/', $this->sanitizer->classPath($name, 'action'));
        $last = array_pop($segments);

        $this->sanitizer->ensureStartsWithLetter($last, $name, 'action');

        if ($last === self::SUFFIX) {
            throw new RuntimeException("Action name [{$name}] is only Action; give it a name, e.g. CreatePost or CreatePostAction.");
        }

        $segments[] = str_ends_with($last, self::SUFFIX) ? $last : $last.self::SUFFIX;

        return implode('/', $segments);
    }

    /**
     * Posts/CreatePostAction pairs with Posts/CreatePostInput.
     */
    public function pairedInput(string $actionPath): string
    {
        return Str::beforeLast($actionPath, self::SUFFIX).'Input';
    }

    /**
     * The Gate ability: the action's class name without the suffix, kebab-cased.
     */
    public function ability(string $actionPath): string
    {
        return Str::kebab(Str::beforeLast(class_basename($actionPath), self::SUFFIX));
    }
}
