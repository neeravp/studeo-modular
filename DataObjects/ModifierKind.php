<?php

namespace Studeo\Modular\DataObjects;

/**
 * Every column modifier a schema can carry. The set is closed: anything else is a schema
 * issue, never a method name passed through to a migration.
 */
enum ModifierKind: string
{
    case Nullable = 'nullable';
    case Unique = 'unique';
    case Index = 'index';
    case Primary = 'primary';
    case Unsigned = 'unsigned';
    case Constrained = 'constrained';
    case CascadeOnDelete = 'cascadeOnDelete';
    case NullOnDelete = 'nullOnDelete';
    case DefaultValue = 'default';
    case Cast = 'cast';
    case Hashed = 'hashed';
    case Guarded = 'guarded';
    case Fillable = 'fillable';
    case Context = 'context';

    /**
     * Case-insensitive; "cascade" is the accepted short form of cascadeOnDelete.
     */
    public static function fromName(string $name): ?self
    {
        $name = strtolower($name) === 'cascade' ? 'cascadeOnDelete' : $name;

        $matching = array_filter(self::cases(), fn (self $kind): bool => strtolower($kind->value) === strtolower($name));

        return array_shift($matching);
    }

    /**
     * @return array<int, string>
     */
    public static function names(): array
    {
        return array_map(fn (self $kind): string => $kind->value, self::cases());
    }

    /**
     * Whether the modifier takes an argument in parentheses: none, optional or required.
     */
    public function argument(): string
    {
        return match ($this) {
            self::DefaultValue, self::Cast => 'required',
            self::Constrained, self::Fillable => 'optional',
            default => 'none',
        };
    }

    /**
     * Whether the modifier changes the migration column (the others only shape the model and forms).
     */
    public function isColumnModifier(): bool
    {
        return in_array($this, [self::Nullable, self::Unique, self::Index, self::Primary, self::Unsigned, self::Constrained, self::CascadeOnDelete, self::NullOnDelete], true);
    }
}
