<?php

namespace Studeo\Modular\Support;

use InvalidArgumentException;

/**
 * An enum field names a PHP enum by class name: status:enum(PostStatus). The class lives
 * in the module's Enums namespace and is created with module:enum. Inline case lists
 * from the old syntax are an error, never a fallback.
 */
final class EnumFieldReference
{
    public static function classNameOrFail(string $field, string $reference): string
    {
        return preg_match('/^[A-Z][A-Za-z0-9]*$/', $reference) === 1 ? $reference : throw self::legacy($field, $reference);
    }

    public static function legacy(string $field, string $cases): InvalidArgumentException
    {
        return new InvalidArgumentException(
            "Field [{$field}]: inline enum cases [{$cases}] are no longer supported. "
            .'Create the enum with '.self::create().' and reference it by class name, e.g. '.self::example($field).'.'
        );
    }

    public static function missing(string $field): InvalidArgumentException
    {
        return new InvalidArgumentException(
            "Field [{$field}]: an enum field must name its enum class, e.g. ".self::example($field).'. Create the enum with '.self::create().'.'
        );
    }

    /**
     * The working form of the command, for any message that sends the user to module:enum.
     */
    public static function create(string $name = 'PostStatus'): string
    {
        return "module:enum {$name} --cases=draft,published";
    }

    private static function example(string $field): string
    {
        return "{$field}:enum(PostStatus)";
    }
}
