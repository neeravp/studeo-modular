<?php

namespace Studeo\Modular\Support\Schema;

use InvalidArgumentException;
use Studeo\Modular\DataObjects\FieldDefault;
use Studeo\Modular\DataObjects\FieldModifier;
use Studeo\Modular\DataObjects\ModifierKind;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\RawField;
use Studeo\Modular\DataObjects\SchemaIssue;
use Studeo\Modular\Support\FieldDefaultParser;

/**
 * The rules for one field: its name, structure, type and arguments, modifiers, default and
 * enum. Defaults and enum references are checked by FieldDefaultParser and EnumFieldReference,
 * not re-implemented here.
 */
final class FieldRules
{
    /** Columns the create migration declares itself. */
    private const array RESERVED = ['id', 'created_at', 'updated_at', 'deleted_at'];

    /** Names that would shadow a property or method of an Eloquent model. */
    private const array MODEL_PROPERTIES = [
        'pivot', 'exists', 'attributes', 'original', 'relations', 'table', 'connection',
        'timestamps', 'fillable', 'guarded', 'casts', 'dates', 'hidden', 'visible', 'appends',
    ];

    public function __construct(
        private readonly EnumRules $enums = new EnumRules,
        private readonly ModifierRules $modifierRules = new ModifierRules,
    ) {}

    /**
     * @return array<int, SchemaIssue>
     */
    public function check(RawField $field, ?ModuleDefinition $module): array
    {
        $path = "fields.{$field->name}";
        $canonical = SchemaTypes::canonical($field->type);
        [$modifiers, $modifierIssues] = $this->modifiers($field, $path, $canonical);
        [$default, $defaultIssues] = $this->defaultOf($field, $path, $canonical);

        return [
            ...$this->name($field->name, $path),
            ...$this->structure($field, $path),
            ...$this->type($field, $path, $canonical),
            ...$modifierIssues,
            ...$defaultIssues,
            ...$this->enums->check($field, $path, $canonical, $default, $module),
        ];
    }

    /**
     * @return array<int, SchemaIssue>
     */
    private function name(string $name, string $path): array
    {
        return match (true) {
            preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1 => [SchemaIssue::error($path, 'field_name', "Field name [{$name}] must be lower snake case and start with a letter, e.g. author_id.")],
            in_array($name, self::RESERVED, true) => [SchemaIssue::error($path, 'field_reserved', "Field name [{$name}] is reserved: the create migration already declares it.", self::RESERVED)],
            in_array($name, self::MODEL_PROPERTIES, true) => [SchemaIssue::error($path, 'field_reserved', "Field name [{$name}] would shadow a property of an Eloquent model.", self::MODEL_PROPERTIES)],
            default => [],
        };
    }

    /**
     * @return array<int, SchemaIssue>
     */
    private function structure(RawField $field, string $path): array
    {
        return [
            ...array_map(fn (string $problem): SchemaIssue => SchemaIssue::error($path, 'field_shape', "Field [{$field->name}]: {$problem}."), $field->malformed),
            ...($field->unknownKeys === [] ? [] : [SchemaIssue::error($path, 'field_key', 'Unknown key(s) ['.implode(', ', $field->unknownKeys)."] on field [{$field->name}].", SchemaKeys::FIELD)]),
        ];
    }

    /**
     * @return array<int, SchemaIssue>
     */
    private function type(RawField $field, string $path, ?string $canonical): array
    {
        if ($canonical === null) {
            return [SchemaIssue::error($path, 'field_type', "Field [{$field->name}] has an unknown type [{$field->type}].", SchemaTypes::TYPES)];
        }

        return $this->arguments($field, $path, $canonical);
    }

    /**
     * @return array<int, SchemaIssue>
     */
    private function arguments(RawField $field, string $path, string $canonical): array
    {
        $count = count($field->typeArguments);
        $max = SchemaTypes::argumentCount($canonical)[1];

        if ($count > $max) {
            $takes = $max === 0 ? 'takes no arguments' : "takes at most {$max} argument(s)";

            return [SchemaIssue::error($path, 'field_type_arguments', "Type [{$canonical}] {$takes}, but field [{$field->name}] gave {$count}.")];
        }

        $problem = $this->argumentProblem($canonical, array_map('trim', $field->typeArguments));

        return $problem === null ? [] : [SchemaIssue::error($path, 'field_type_arguments', "Field [{$field->name}]: {$problem}.")];
    }

    /**
     * @param  array<int, string>  $arguments
     */
    private function argumentProblem(string $canonical, array $arguments): ?string
    {
        $numbers = array_map(fn (string $argument): ?int => ctype_digit($argument) ? (int) $argument : null, $arguments);

        return in_array(null, $numbers, true) ? "type [{$canonical}] arguments must be whole numbers" : $this->rangeProblem($canonical, $numbers);
    }

    /**
     * @param  array<int, int>  $numbers
     */
    private function rangeProblem(string $canonical, array $numbers): ?string
    {
        return match (true) {
            in_array($canonical, ['string', 'char'], true) && $numbers !== [] && ($numbers[0] < 1 || $numbers[0] > SchemaTypes::MAX_LENGTH) => "the length of [{$canonical}] must be between 1 and ".SchemaTypes::MAX_LENGTH,
            $canonical === 'decimal' && $numbers !== [] && ($numbers[0] < 1 || $numbers[0] > 65) => 'the precision of [decimal] must be between 1 and 65',
            $canonical === 'decimal' && count($numbers) === 2 && $numbers[1] > $numbers[0] => 'the scale of [decimal] cannot exceed its precision',
            $canonical === 'float' && $numbers !== [] && ($numbers[0] < 1 || $numbers[0] > 53) => 'the precision of [float] must be between 1 and 53',
            default => null,
        };
    }

    /**
     * @return array{0: array<int, FieldModifier>, 1: array<int, SchemaIssue>}
     */
    private function modifiers(RawField $field, string $path, ?string $canonical): array
    {
        $typed = [];
        $issues = [];

        foreach ($field->modifiers as $token) {
            $modifier = FieldModifier::parse($token);
            $typed = $modifier === null ? $typed : [...$typed, $modifier];
            $issues = [...$issues, ...($modifier === null ? [$this->unknownModifier($field, $path, $token)] : $this->argumentIssues($field, $path, $modifier))];
        }

        return [$typed, [...$issues, ...$this->modifierRules->combinations($field->name, $path, $typed, $canonical)]];
    }

    private function unknownModifier(RawField $field, string $path, string $token): SchemaIssue
    {
        return SchemaIssue::error($path, 'field_modifier', "Field [{$field->name}] has an unknown modifier [{$token}].", ModifierKind::names());
    }

    /**
     * @return array<int, SchemaIssue>
     */
    private function argumentIssues(RawField $field, string $path, FieldModifier $modifier): array
    {
        $problem = $this->modifierRules->argumentProblem($modifier);

        return $problem === null ? [] : [SchemaIssue::error($path, 'field_modifier_argument', "Field [{$field->name}]: modifier [{$modifier->kind->value}] {$problem}.")];
    }

    /**
     * @return array{0: FieldDefault|null, 1: array<int, SchemaIssue>}
     */
    private function defaultOf(RawField $field, string $path, ?string $canonical): array
    {
        try {
            $token = $field->defaultToken();

            return $this->hasNothingToParse($token, $canonical)
                ? [null, []]
                : [FieldDefaultParser::parse($field->name, $canonical, $field->typeArguments, $field->isNullable(), $token), []];
        } catch (InvalidArgumentException $exception) {
            return [null, [SchemaIssue::error($path, 'field_default', $exception->getMessage())]];
        }
    }

    /**
     * @phpstan-assert-if-false string $token
     * @phpstan-assert-if-false string $canonical
     */
    private function hasNothingToParse(?string $token, ?string $canonical): bool
    {
        return $token === null || $canonical === null;
    }
}
