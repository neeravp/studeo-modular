<?php

namespace Studeo\Modular\Support\Schema;

use Studeo\Modular\DataObjects\FieldModifier;
use Studeo\Modular\DataObjects\ModifierKind;
use Studeo\Modular\DataObjects\SchemaIssue;

/**
 * The rules for a field's modifiers once they are typed: the shape of each argument, no
 * modifier twice, which types a modifier applies to, and the combinations that contradict.
 */
final class ModifierRules
{
    private const string CLASS_NAME = '/^\\\\?[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/';

    private const string CAST_KEYWORDS = '/^(array|collection|object|json|boolean|integer|float|double|string|date|datetime|immutable_date|immutable_datetime|timestamp|hashed|encrypted|decimal:\d+|(date|datetime|immutable_date|immutable_datetime):.+)$/';

    /**
     * What is wrong with a modifier's argument, or null.
     */
    public function argumentProblem(FieldModifier $modifier): ?string
    {
        return match (true) {
            $modifier->kind->argument() === 'none' && $modifier->argument !== null => 'takes no argument',
            $modifier->kind->argument() === 'required' && ($modifier->argument === null || $modifier->argument === '') => 'needs an argument, e.g. '.$modifier->kind->value.'(...)',
            $modifier->kind === ModifierKind::Fillable && $modifier->argument !== null && ! in_array($modifier->argument, ['true', 'false'], true) => 'takes true or false',
            $modifier->kind === ModifierKind::Constrained && $modifier->argument !== null && preg_match('/^[a-z][a-z0-9_]*$/', $modifier->argument) !== 1 => 'takes a lower snake case table name',
            $modifier->kind === ModifierKind::Cast && ! $this->isCast((string) $modifier->argument) => 'takes a class name or an Eloquent cast such as decimal:2',
            default => null,
        };
    }

    /**
     * @param  array<int, FieldModifier>  $modifiers
     * @return array<int, SchemaIssue>
     */
    public function combinations(string $field, string $path, array $modifiers, ?string $canonical): array
    {
        $kinds = array_map(fn (FieldModifier $modifier): ModifierKind => $modifier->kind, $modifiers);
        $problems = [...$this->duplicates($kinds), ...$this->contradictions($kinds), ...$this->applicability($kinds, $canonical)];

        return [
            ...array_map(fn (string $problem): SchemaIssue => SchemaIssue::error($path, 'field_modifier', "Field [{$field}]: {$problem}."), $problems),
            ...array_map(fn (string $warning): SchemaIssue => SchemaIssue::warning($path, 'field_modifier', "Field [{$field}]: {$warning}."), $this->redundancies($kinds)),
        ];
    }

    private function isCast(string $argument): bool
    {
        return preg_match(self::CLASS_NAME, $argument) === 1 || preg_match(self::CAST_KEYWORDS, $argument) === 1;
    }

    /**
     * @param  array<int, ModifierKind>  $kinds
     * @return array<int, string>
     */
    private function duplicates(array $kinds): array
    {
        $repeated = array_keys(array_filter(array_count_values(array_map(fn (ModifierKind $kind): string => $kind->value, $kinds)), fn (int $count): bool => $count > 1));

        return array_map(fn (string $name): string => "modifier [{$name}] is given more than once", $repeated);
    }

    /**
     * @param  array<int, ModifierKind>  $kinds
     * @return array<int, string>
     */
    private function contradictions(array $kinds): array
    {
        return array_values(array_filter([
            $this->has($kinds, ModifierKind::Fillable) && $this->has($kinds, ModifierKind::Guarded) ? 'fillable and guarded contradict each other' : null,
            $this->has($kinds, ModifierKind::Primary) && $this->has($kinds, ModifierKind::Nullable) ? 'a primary column cannot be nullable' : null,
            $this->has($kinds, ModifierKind::NullOnDelete) && ! $this->has($kinds, ModifierKind::Nullable) ? 'nullOnDelete needs a nullable column' : null,
            ($this->has($kinds, ModifierKind::CascadeOnDelete) || $this->has($kinds, ModifierKind::NullOnDelete)) && ! $this->has($kinds, ModifierKind::Constrained) ? 'cascadeOnDelete and nullOnDelete need constrained' : null,
        ]));
    }

    /**
     * @param  array<int, ModifierKind>  $kinds
     * @return array<int, string>
     */
    private function applicability(array $kinds, ?string $canonical): array
    {
        return $canonical === null ? [] : array_values(array_filter([
            $this->has($kinds, ModifierKind::Unsigned) && ! SchemaTypes::isInteger($canonical) ? "unsigned only applies to integer types, not [{$canonical}]" : null,
            $this->has($kinds, ModifierKind::Constrained) && ! SchemaTypes::isForeignKey($canonical) ? "constrained only applies to foreign key types, not [{$canonical}]" : null,
            $this->has($kinds, ModifierKind::Hashed) && ! SchemaTypes::isText($canonical) ? "hashed only applies to string types, not [{$canonical}]" : null,
        ]));
    }

    /**
     * @param  array<int, ModifierKind>  $kinds
     * @return array<int, string>
     */
    private function redundancies(array $kinds): array
    {
        return $this->has($kinds, ModifierKind::Index) && $this->has($kinds, ModifierKind::Unique)
            ? ['index is redundant beside unique']
            : [];
    }

    /**
     * @param  array<int, ModifierKind>  $kinds
     */
    private function has(array $kinds, ModifierKind $kind): bool
    {
        return in_array($kind, $kinds, true);
    }
}
