<?php

namespace Studeo\Modular\DataObjects;

/**
 * One typed modifier on a field: its kind and its argument, if it takes one. A default's
 * argument keeps its quotes as written, because the quotes decide whether it is text.
 */
final readonly class FieldModifier
{
    public function __construct(
        public ModifierKind $kind,
        public ?string $argument = null,
    ) {}

    /**
     * A modifier token such as "nullable", "default(5)" or "constrained('users')", or null when
     * the token is not one of the known modifiers.
     */
    public static function parse(string $token): ?self
    {
        if (preg_match('/^\s*([A-Za-z_]+)\s*(?:\((.*)\))?\s*$/s', $token, $match) !== 1) {
            return null;
        }

        $kind = ModifierKind::fromName($match[1]);
        $argument = isset($match[2]) ? trim($match[2]) : null;

        return $kind === null ? null : new self($kind, $kind === ModifierKind::Constrained ? self::unquote($argument) : $argument);
    }

    public function token(): string
    {
        return $this->argument === null ? $this->kind->value : "{$this->kind->value}({$this->argument})";
    }

    private static function unquote(?string $argument): ?string
    {
        return $argument !== null && preg_match('/^([\'"])(.*)\1$/s', $argument, $match) === 1 ? $match[2] : $argument;
    }
}
