<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * Normalises an enum name to a studly identifier (post status becomes PostStatus). An enum is
 * always Enums/{Name}.php: a schema reference names the class alone, so a path is an error.
 */
final class ResolveEnumNameAction
{
    /**
     * The names PHP cannot load as an enum name, lower case: its reserved keywords (a parse error)
     * and its reserved type names (a compile error). EnumReservedNamesTest loads each candidate in
     * PHP itself, so this list is exactly what PHP refuses and a name PHP accepts is never added.
     */
    private const array UNLOADABLE = [
        'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch', 'class', 'clone', 'const', 'continue', 'declare',
        'default', 'die', 'do', 'echo', 'else', 'elseif', 'empty', 'enddeclare', 'endfor', 'endforeach', 'endif', 'endswitch',
        'endwhile', 'eval', 'exit', 'extends', 'final', 'finally', 'fn', 'for', 'foreach', 'function', 'global', 'goto', 'if',
        'implements', 'include', 'instanceof', 'insteadof', 'interface', 'isset', 'list', 'match', 'namespace',
        'new', 'or', 'print', 'private', 'protected', 'public', 'readonly', 'require', 'return', 'static',
        'switch', 'throw', 'trait', 'try', 'unset', 'use', 'var', 'while', 'xor', 'yield',
        'self', 'parent', 'int', 'float', 'bool', 'string', 'true', 'false', 'null', 'void', 'iterable', 'object', 'mixed', 'never',
    ];

    public function execute(string $name): string
    {
        $name = trim($name);

        if ($this->hasPath($name)) {
            throw new RuntimeException("Enum name [{$name}] has a path in it. Enums live in Enums/{Name}.php with no subfolders, because a schema refers to them by class name alone, e.g. status:enum(PostStatus).");
        }

        $studly = Str::studly($name);

        return match (true) {
            preg_match('/^[A-Z][A-Za-z0-9]*$/', $studly) !== 1 => throw new RuntimeException("Enum name [{$name}] is not a valid class name. Use letters and digits, starting with a letter, e.g. PostStatus."),
            in_array(strtolower($studly), self::UNLOADABLE, true) => throw new RuntimeException("Enum name [{$studly}] cannot be used: PHP cannot use it as an enum name because it is reserved. Choose another, e.g. PostStatus."),
            default => $studly,
        };
    }

    private function hasPath(string $name): bool
    {
        return str_contains($name, '/') || str_contains($name, '\\');
    }
}
