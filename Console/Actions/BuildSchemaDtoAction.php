<?php

namespace Studeo\Modular\Console\Actions;

use RuntimeException;
use Studeo\Modular\DataObjects\DtoKind;
use Studeo\Modular\DataObjects\FieldDefault;
use Studeo\Modular\DataObjects\FieldDefinition;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\SchemaDefinition;
use Studeo\Modular\Support\RuleExpression;
use Studeo\Modular\Support\RuleFormatter;
use Studeo\Modular\Support\Schema\DtoProperties;
use Studeo\Modular\Support\Schema\EnumFileReader;
use Studeo\Modular\Support\ValidationRuleResolver;

/**
 * Fills the constructor, the imports and (for an Input) rules() of a DTO stub from a schema.
 * Which fields go in, and in what order, is DtoProperties' decision; this renders them. It
 * throws before anything is written when an enum default cannot be turned into a case.
 */
final class BuildSchemaDtoAction
{
    private const string CONSTRUCTOR_GAP = '__construct() {}';

    private const string RULES_GAP = 'return [];';

    public function __construct(
        private readonly EnumFileReader $enums = new EnumFileReader,
    ) {}

    /**
     * @throws RuntimeException when an enum default's case cannot be read from the enum file
     */
    public function execute(string $stub, ModuleDefinition $module, SchemaDefinition $schema, DtoKind $kind): string
    {
        $fields = DtoProperties::fields($schema, $kind);
        $parameters = array_map(fn (FieldDefinition $field): string => $this->parameter($field, $kind, $module), $fields);
        $content = str_replace(self::CONSTRUCTOR_GAP, "__construct(\n".implode("\n", $parameters)."\n    ) {}", $stub);
        $content = $kind === DtoKind::Input ? str_replace(self::RULES_GAP, "return [\n".$this->rules($schema, $fields)."\n        ];", $content) : $content;

        return $this->withImports($content, $kind, $fields);
    }

    private function parameter(FieldDefinition $field, DtoKind $kind, ModuleDefinition $module): string
    {
        $attribute = $this->isInputContext($kind, $field) ? "        #[Context]\n" : '';
        $name = lcfirst(str($field->name)->studly()->toString());

        return "{$attribute}        public ".DtoProperties::type($field)." \${$name}".$this->default($field, $kind, $module).',';
    }

    private function default(FieldDefinition $field, DtoKind $kind, ModuleDefinition $module): string
    {
        return match (true) {
            $kind === DtoKind::Output => '',
            $field->default !== null => ' = '.$this->defaultExpression($field, $module),
            $field->isNullable() => ' = null',
            default => '',
        };
    }

    private function defaultExpression(FieldDefinition $field, ModuleDefinition $module): string
    {
        /** @var FieldDefault $default */
        $default = $field->default;

        return $field->isEnum()
            ? $default->dto(class_basename($field->enumClass()), $this->enumCase($field, $module))
            : $default->dto();
    }

    private function enumCase(FieldDefinition $field, ModuleDefinition $module): string
    {
        $name = class_basename($field->enumClass());
        $file = "Enums/{$name}.php";
        $cases = $this->enums->read("{$module->path}/{$file}")['cases'] ?? [];
        $case = array_search($field->default?->value, $cases, true);

        return $case === false
            ? throw new RuntimeException("Field [{$field->name}]: the case for default [{$field->default?->value}] could not be read from {$file} in module [{$module->name}]; nothing was written.")
            : (string) $case;
    }

    /**
     * Context fields are not in the payload, so they get no rule. Every other field keeps the
     * schema's order here, whatever order the constructor needs.
     *
     * @param  array<int, FieldDefinition>  $fields
     */
    private function rules(SchemaDefinition $schema, array $fields): string
    {
        $names = array_map(fn (FieldDefinition $field): string => $field->name, $fields);
        $inPayload = array_filter($schema->fields, fn (FieldDefinition $field): bool => $this->isPayloadField($field, $names));

        return implode("\n", array_map(fn (FieldDefinition $field): string => RuleFormatter::line($field->name, $this->fieldRules($field)), $inPayload));
    }

    /**
     * A field with a default may be left out of the payload, so it is `sometimes` rather than `required`.
     *
     * @return array<int, string|RuleExpression>
     */
    private function fieldRules(FieldDefinition $field): array
    {
        $rules = ValidationRuleResolver::resolve($field);

        return $this->isSometimes($field)
            ? array_map(fn (mixed $rule): mixed => $rule === 'required' ? 'sometimes' : $rule, $rules)
            : $rules;
    }

    /**
     * @param  array<int, FieldDefinition>  $fields
     */
    private function withImports(string $content, DtoKind $kind, array $fields): string
    {
        $base = $kind === DtoKind::Input ? 'Studeo\Modular\Support\InputData' : 'Studeo\Modular\Support\OutputData';
        $hasContext = array_filter($fields, fn (FieldDefinition $field): bool => $this->isInputContext($kind, $field)) !== [];
        $imports = array_unique(array_filter([$base, $hasContext ? 'Studeo\Modular\Support\Attributes\Context' : null, ...array_map(DtoProperties::import(...), $fields)]));
        sort($imports, SORT_STRING | SORT_FLAG_CASE);

        return str_replace("use {$base};", implode("\n", array_map(fn (string $import): string => "use {$import};", $imports)), $content);
    }

    private function isInputContext(DtoKind $kind, FieldDefinition $field): bool
    {
        return $kind === DtoKind::Input && $field->isContext();
    }

    /**
     * @param  array<int, string>  $names  the names of the fields the DTO carries
     */
    private function isPayloadField(FieldDefinition $field, array $names): bool
    {
        return in_array($field->name, $names, true) && ! $field->isContext();
    }

    private function isSometimes(FieldDefinition $field): bool
    {
        return $field->default !== null && ! $field->isNullable();
    }
}
