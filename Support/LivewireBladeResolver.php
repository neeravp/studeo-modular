<?php

namespace Studeo\Modular\Support;

use Illuminate\Support\Str;
use Studeo\Modular\DataObjects\FieldDefinition;

final class LivewireBladeResolver
{
    /**
     * The controls of a form, one per field. Its state lives in a Form object, so $stateKey
     * ("form.") prefixes every wire:model and @error key.
     *
     * @param  array<int, FieldDefinition>  $fields
     */
    public static function resolve(array $fields, string $stateKey = ''): string
    {
        return implode("\n\n", array_map(
            fn (FieldDefinition $field): string => str_replace(
                ["wire:model=\"{$field->name}\"", "@error('{$field->name}')"],
                ["wire:model=\"{$stateKey}{$field->name}\"", "@error('{$stateKey}{$field->name}')"],
                self::renderFieldControl($field),
            ),
            $fields,
        ));
    }

    public static function renderFieldControl(FieldDefinition $field): string
    {
        return match (true) {
            FormFieldKinds::isTextarea($field), FormFieldKinds::isArray($field) => self::renderTextareaField($field),
            FormFieldKinds::isBoolean($field) => self::renderCheckboxField($field),
            FormFieldKinds::isEnum($field) => self::renderSelectField($field),
            FormFieldKinds::isNumeric($field) => self::renderNumberField($field),
            FormFieldKinds::isDate($field) => self::renderDateField($field),
            default => self::renderTextField($field),
        };
    }

    public static function resolveFieldLabel(FieldDefinition $field): string
    {
        $name = $field->name;

        if (self::hasIdSuffix($name)) {
            $name = substr($name, 0, -3);
        }

        return Str::headline($name);
    }

    private static function renderFormGroup(FieldDefinition $field, string $innerHtml): string
    {
        return <<<BLADE
                <div>
{$innerHtml}
                    @error('{$field->name}')
                        <div class="text-sm text-red-600 mt-1">{{ \$message }}</div>
                    @enderror
                </div>
BLADE;
    }

    private static function buildFormField(FieldDefinition $field, string $controlHtml): string
    {
        $label = self::resolveFieldLabel($field);

        $innerHtml = <<<BLADE
                    <label for="{$field->name}" class="block text-sm font-medium text-gray-700">{$label}</label>
{$controlHtml}
BLADE;

        return self::renderFormGroup($field, $innerHtml);
    }

    private static function renderTextField(FieldDefinition $field): string
    {
        $type = self::resolveInputType($field);

        return self::buildFormField($field, <<<BLADE
                    <input
                        id="{$field->name}"
                        wire:model="{$field->name}"
                        type="{$type}"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                    />
BLADE);
    }

    private static function resolveInputType(FieldDefinition $field): string
    {
        return match (true) {
            self::isNamed($field, 'email') => 'email',
            self::isNamed($field, 'password') => 'password',
            self::isNamed($field, 'url') => 'url',
            default => 'text',
        };
    }

    private static function renderTextareaField(FieldDefinition $field): string
    {
        return self::buildFormField($field, <<<BLADE
                    <textarea
                        id="{$field->name}"
                        wire:model="{$field->name}"
                        rows="4"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                    ></textarea>
BLADE);
    }

    private static function renderNumberField(FieldDefinition $field): string
    {
        $step = self::resolveNumberStep($field);

        return self::buildFormField($field, <<<BLADE
                    <input
                        id="{$field->name}"
                        wire:model="{$field->name}"
                        type="number"{$step}
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                    />
BLADE);
    }

    private static function resolveNumberStep(FieldDefinition $field): string
    {
        return FormFieldKinds::isDecimal($field) ? ' step="any"' : '';
    }

    private static function renderCheckboxField(FieldDefinition $field): string
    {
        $label = self::resolveFieldLabel($field);

        $innerHtml = <<<BLADE
                    <div class="flex items-center">
                        <input
                            id="{$field->name}"
                            wire:model="{$field->name}"
                            type="checkbox"
                            class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                        />
                        <label for="{$field->name}" class="ml-2 block text-sm text-gray-900">{$label}</label>
                    </div>
BLADE;

        return self::renderFormGroup($field, $innerHtml);
    }

    private static function renderSelectField(FieldDefinition $field): string
    {
        $options = self::renderSelectOptions($field);

        return self::buildFormField($field, <<<BLADE
                    <select
                        id="{$field->name}"
                        wire:model="{$field->name}"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                    >
{$options}
                    </select>
BLADE);
    }

    /**
     * The options come from the enum itself, at render time, so the form never copies its cases.
     */
    private static function renderSelectOptions(FieldDefinition $field): string
    {
        $indent = str_repeat(' ', 24);
        $enum = '\\'.$field->enumClass();

        return "{$indent}<option value=\"\">Select...</option>\n"
            ."{$indent}@foreach ({$enum}::cases() as \$case)\n"
            ."{$indent}    <option value=\"{{ \$case->value }}\">{{ \$case->name }}</option>\n"
            ."{$indent}@endforeach";
    }

    private static function renderDateField(FieldDefinition $field): string
    {
        $type = self::resolveDateInputType($field);

        return self::buildFormField($field, <<<BLADE
                    <input
                        id="{$field->name}"
                        wire:model="{$field->name}"
                        type="{$type}"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                    />
BLADE);
    }

    private static function resolveDateInputType(FieldDefinition $field): string
    {
        return strtolower($field->type) === 'date' ? 'date' : 'datetime-local';
    }

    private static function hasIdSuffix(string $name): bool
    {
        return str_ends_with(strtolower($name), '_id') && $name !== 'id';
    }

    /**
     * The field is called exactly this, or its name ends in an underscore and this (billing_email).
     */
    private static function isNamed(FieldDefinition $field, string $name): bool
    {
        return strtolower($field->name) === $name || str_ends_with(strtolower($field->name), "_{$name}");
    }
}
