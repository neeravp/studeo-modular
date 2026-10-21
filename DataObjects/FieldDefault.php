<?php

namespace Studeo\Modular\DataObjects;

use LogicException;

/**
 * One typed default for a field, already checked against the field's type, with one
 * renderer per target. Numbers declared as float or double keep the text they were
 * written with, so nothing is rounded on the way to the migration.
 */
final readonly class FieldDefault
{
    /**
     * @param  string  $kind  null, int, bool, string, decimal, float or enum
     */
    public function __construct(
        public int|bool|string|null $value,
        public string $kind,
    ) {}

    /**
     * The argument of ->default() in a migration.
     */
    public function migration(): string
    {
        return $this->kind === 'float' ? (string) $this->value : $this->literal();
    }

    /**
     * The initial value of a Livewire property. Decimals and floats are strings there.
     */
    public function livewire(): string
    {
        return $this->kind === 'float' ? $this->quoted() : $this->literal();
    }

    /**
     * The initial value in a Vue useForm() state. Decimals are strings, floats are numbers.
     */
    public function vue(): string
    {
        return $this->migration();
    }

    /**
     * The default as a constant expression in a DTO constructor: decimals are strings and floats
     * keep their text. An enum default is a case, so the caller names the class and the case.
     */
    public function dto(?string $enumClass = null, ?string $case = null): string
    {
        return match (true) {
            $this->kind !== 'enum' => $this->migration(),
            $enumClass === null || $case === null => throw new LogicException("The enum default [{$this->value}] needs the enum class and its case to be rendered."),
            default => "{$enumClass}::{$case}",
        };
    }

    private function literal(): string
    {
        return match (true) {
            $this->value === null => 'null',
            is_bool($this->value) => $this->value ? 'true' : 'false',
            is_int($this->value) => (string) $this->value,
            default => $this->quoted(),
        };
    }

    private function quoted(): string
    {
        return "'".addcslashes((string) $this->value, "'\\")."'";
    }
}
