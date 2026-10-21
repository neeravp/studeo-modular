<?php

namespace Studeo\Modular\DataObjects;

use InvalidArgumentException;
use LogicException;
use Studeo\Modular\Support\FakerDefinitionResolver;
use Studeo\Modular\Support\FieldDefaultParser;
use Studeo\Modular\Support\FieldTypeMap;
use Studeo\Modular\Support\MigrationColumnResolver;
use Studeo\Modular\Support\PhpImports;

final readonly class FieldDefinition
{
    private const array DEFAULT_GUARDED = [
        'id',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /** The default, typed and checked against the field's type when the field is built. */
    public ?FieldDefault $default;

    /** @var array<int, FieldModifier> */
    public array $modifiers;

    /**
     * @param  array<int, string|FieldModifier>  $modifiers  typed modifiers, or modifier tokens such as "nullable"
     * @param  string|null  $enum  the enum class: a short name until resolved against a module, then its full name
     * @param  array<int, string>  $typeArguments
     */
    public function __construct(
        public string $name,
        public string $type = 'string',
        array $modifiers = [],
        public ?string $enum = null,
        public ?bool $fillable = null,
        public ?string $cast = null,
        public array $typeArguments = [],
    ) {
        $this->modifiers = array_map(
            fn (string|FieldModifier $modifier): FieldModifier => $modifier instanceof FieldModifier
                ? $modifier
                : (FieldModifier::parse($modifier) ?? throw new InvalidArgumentException("Field [{$name}] has an unknown modifier [{$modifier}].")),
            $modifiers,
        );
        $token = $this->modifier(ModifierKind::DefaultValue)?->argument;

        $this->default = $token === null
            ? null
            : FieldDefaultParser::parse($this->name, $this->type, $this->typeArguments, $this->isNullable(), $token);
    }

    public function isEnum(): bool
    {
        return $this->enum !== null;
    }

    /**
     * The enum's full class name, without a leading backslash.
     */
    public function enumClass(): string
    {
        if (! str_contains((string) $this->enum, '\\')) {
            throw new LogicException("Enum [{$this->enum}] on field [{$this->name}] is not resolved to a module; call forModule() first.");
        }

        return ltrim((string) $this->enum, '\\');
    }

    /**
     * An enum named by its short class name lives in the module's Enums namespace.
     */
    public function forModule(string $moduleNamespace): self
    {
        if (! $this->isShortEnum()) {
            return $this;
        }

        return new self($this->name, $this->type, $this->modifiers, "{$moduleNamespace}\\Enums\\{$this->enum}", $this->fillable, $this->cast, $this->typeArguments);
    }

    public function modifier(ModifierKind $kind): ?FieldModifier
    {
        $matching = array_filter($this->modifiers, fn (FieldModifier $modifier): bool => $modifier->kind === $kind);

        return array_shift($matching);
    }

    public function hasModifier(string $name): bool
    {
        $kind = ModifierKind::fromName($name);

        if ($kind === null) {
            return false;
        }

        return $this->modifier($kind) !== null;
    }

    public function isNullable(): bool
    {
        return $this->modifier(ModifierKind::Nullable) !== null;
    }

    public function isUnique(): bool
    {
        return $this->modifier(ModifierKind::Unique) !== null;
    }

    public function isPrimary(): bool
    {
        return $this->modifier(ModifierKind::Primary) !== null || $this->type === 'id';
    }

    public function isIndex(): bool
    {
        return $this->modifier(ModifierKind::Index) !== null;
    }

    public function isGuarded(): bool
    {
        return $this->modifier(ModifierKind::Guarded) !== null;
    }

    /**
     * Whether the field is supplied from outside the payload (a #[Context] property of an Input).
     */
    public function isContext(): bool
    {
        return $this->modifier(ModifierKind::Context) !== null;
    }

    public function phpType(): string
    {
        if ($this->isEnum()) {
            return ($this->isNullable() ? '?' : '').'\\'.$this->enumClass();
        }

        $type = FieldTypeMap::phpType($this->type) ?? 'string';

        return ($this->isNullable() ? '?' : '').$type;
    }

    /**
     * The docblock line; a class type is named through the imports when given, fully qualified otherwise.
     */
    public function phpDocProperty(?PhpImports $imports = null): string
    {
        return '@property '.($imports?->type($this->phpType()) ?? $this->phpType())." \${$this->name}";
    }

    public function castType(): ?string
    {
        return $this->resolveExplicitCast()
            ?? $this->resolveModifierCast()
            ?? $this->resolveHashedCast()
            ?? ($this->isEnum() ? $this->enumClass() : null)
            ?? $this->resolveDefaultCast();
    }

    public function isFillable(): bool
    {
        return $this->fillable ?? $this->resolveFillable();
    }

    public function migrationDefinition(): string
    {
        return MigrationColumnResolver::resolve($this);
    }

    public function fakerDefinition(): string
    {
        return FakerDefinitionResolver::resolve($this);
    }

    private function isShortEnum(): bool
    {
        return $this->enum !== null && ! str_contains($this->enum, '\\');
    }

    private function resolveFillable(): bool
    {
        return ! $this->isGuarded() && ($this->modifier(ModifierKind::Fillable) !== null || $this->isFillableByConvention());
    }

    private function isFillableByConvention(): bool
    {
        return ! in_array(strtolower($this->name), self::DEFAULT_GUARDED, true) && ! $this->isPrimary();
    }

    private function resolveExplicitCast(): ?string
    {
        return ($this->cast !== null && trim($this->cast) !== '') ? trim($this->cast) : null;
    }

    private function resolveModifierCast(): ?string
    {
        return $this->modifier(ModifierKind::Cast)?->argument;
    }

    private function resolveHashedCast(): ?string
    {
        if ($this->modifier(ModifierKind::Hashed) !== null) {
            return 'hashed';
        }

        return $this->isPasswordName(strtolower($this->name)) ? 'hashed' : null;
    }

    private function isPasswordName(string $lowerName): bool
    {
        return $lowerName === 'password' || str_ends_with($lowerName, '_password');
    }

    private function resolveDefaultCast(): ?string
    {
        if (strtolower($this->type) !== 'decimal') {
            return FieldTypeMap::cast($this->type);
        }

        return isset($this->typeArguments[1]) ? "decimal:{$this->typeArguments[1]}" : FieldTypeMap::cast($this->type);
    }
}
