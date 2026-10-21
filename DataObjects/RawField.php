<?php

namespace Studeo\Modular\DataObjects;

use Studeo\Modular\Support\FieldDefaultParser;
use Studeo\Modular\Support\Schema\SchemaTypes;

/**
 * A field exactly as the author wrote it, from the CLI form or a structured file: nothing
 * checked, nothing resolved. The validator reads it; the builder turns it into a FieldDefinition.
 */
final readonly class RawField
{
    /**
     * @param  array<int, string>  $typeArguments
     * @param  array<int, string>  $modifiers  modifier tokens as written, such as "nullable" or "default(5)"
     * @param  string|null  $enum  the enum reference as written: a class name, or a legacy case list
     * @param  array<int, string>  $unknownKeys  config keys the structured form does not know
     * @param  array<int, string>  $malformed  structural problems found while reading
     */
    public function __construct(
        public string $name,
        public string $type = 'string',
        public array $typeArguments = [],
        public array $modifiers = [],
        public ?string $enum = null,
        public ?string $legacyCases = null,
        public array $unknownKeys = [],
        public int|float|null $defaultNumber = null,
        public array $malformed = [],
    ) {}

    /**
     * The canonical type name, or the type as written when it is not a known one.
     */
    public function canonicalType(): string
    {
        return SchemaTypes::canonical($this->type) ?? $this->type;
    }

    /**
     * The token of the default, as written. A number written for a decimal in a structured file
     * becomes the string at the declared scale; that can fail, with the parser's own message.
     */
    public function defaultToken(): ?string
    {
        if ($this->hasDecimalNumberDefault()) {
            return FieldDefaultParser::decimalFromNumber($this->name, 'decimal', $this->typeArguments, $this->defaultNumber);
        }

        $defaults = array_filter(
            array_map(FieldModifier::parse(...), $this->modifiers),
            fn (?FieldModifier $modifier): bool => $modifier?->kind === ModifierKind::DefaultValue,
        );

        return array_shift($defaults)?->argument;
    }

    /**
     * @phpstan-assert-if-true int|float $this->defaultNumber
     */
    private function hasDecimalNumberDefault(): bool
    {
        return $this->defaultNumber !== null && $this->canonicalType() === 'decimal';
    }

    public function isNullable(): bool
    {
        return in_array('nullable', array_map(fn (string $token): string => strtolower(trim($token)), $this->modifiers), true);
    }
}
