<?php

namespace Studeo\Modular\Support;

use Illuminate\Support\Str;
use Studeo\Modular\DataObjects\FieldDefinition;

final class VueFormResolver
{
    /**
     * The initial state of useForm(), one `name: value,` line per field, each ending in a newline.
     *
     * @param  array<int, FieldDefinition>  $fields
     */
    public static function resolveFormState(array $fields): string
    {
        return implode('', array_map(fn (FieldDefinition $field): string => '  '.self::resolveFieldStateLine($field)."\n", $fields));
    }

    /**
     * The members of the TypeScript state type, one `name: type` line per field, each ending in a newline.
     *
     * @param  array<int, FieldDefinition>  $fields
     */
    public static function resolveFormType(array $fields): string
    {
        return implode('', array_map(fn (FieldDefinition $field): string => "  {$field->name}: ".self::resolveFieldType($field)."\n", $fields));
    }

    /**
     * The template markup of every control, each followed by a blank line.
     *
     * @param  array<int, FieldDefinition>  $fields
     */
    public static function resolveFormFields(array $fields): string
    {
        return implode('', array_map(fn (FieldDefinition $field): string => self::renderFieldControl($field)."\n\n", $fields));
    }

    public static function resolveFieldType(FieldDefinition $field): string
    {
        if (FormFieldKinds::isArray($field)) {
            return 'string';
        }

        $type = match (true) {
            FormFieldKinds::isBoolean($field) => 'boolean',
            FormFieldKinds::isDecimal($field) => 'number | string | null',
            FormFieldKinds::isNumeric($field) => 'number | null',
            default => 'string',
        };

        return self::needsNullSuffix($field, $type) ? "{$type} | null" : $type;
    }

    public static function resolveFieldStateLine(FieldDefinition $field): string
    {
        return "{$field->name}: ".self::resolveFieldStateValue($field).',';
    }

    public static function resolveFieldStateValue(FieldDefinition $field): string
    {
        return $field->default?->vue() ?? match (true) {
            FormFieldKinds::isBoolean($field) => 'false',
            FormFieldKinds::isNumeric($field) => 'null',
            default => "''",
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

    public static function renderFieldControl(FieldDefinition $field): string
    {
        return match (true) {
            FormFieldKinds::isTextarea($field), FormFieldKinds::isArray($field) => self::renderTextareaField($field),
            FormFieldKinds::isBoolean($field) => self::renderCheckboxField($field),
            FormFieldKinds::isNumeric($field) => self::renderNumberField($field),
            FormFieldKinds::isDate($field) => self::renderDateField($field),
            default => self::renderTextField($field),
        };
    }

    private static function renderFormGroup(FieldDefinition $field, string $innerHtml): string
    {
        return <<<HTML
          <div>
{$innerHtml}
            <div v-if="form.errors.{$field->name}" class="text-sm text-red-600 mt-1">{{ form.errors.{$field->name} }}</div>
          </div>
HTML;
    }

    private static function buildFormField(FieldDefinition $field, string $controlHtml): string
    {
        $label = self::resolveFieldLabel($field);

        $innerHtml = <<<HTML
            <label for="{$field->name}" class="block text-sm font-medium text-gray-700">{$label}</label>
{$controlHtml}
HTML;

        return self::renderFormGroup($field, $innerHtml);
    }

    private static function renderTextField(FieldDefinition $field): string
    {
        $type = self::resolveInputType($field);

        return self::buildFormField($field, <<<HTML
            <input
              id="{$field->name}"
              v-model="form.{$field->name}"
              type="{$type}"
              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
            />
HTML);
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
        return self::buildFormField($field, <<<HTML
            <textarea
              id="{$field->name}"
              v-model="form.{$field->name}"
              rows="4"
              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
            />
HTML);
    }

    private static function renderNumberField(FieldDefinition $field): string
    {
        $step = in_array(strtolower($field->type), ['decimal', 'float', 'double'], true) ? ' step="any"' : '';

        return self::buildFormField($field, <<<HTML
            <input
              id="{$field->name}"
              v-model="form.{$field->name}"
              type="number"{$step}
              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
            />
HTML);
    }

    private static function renderCheckboxField(FieldDefinition $field): string
    {
        $label = self::resolveFieldLabel($field);

        $innerHtml = <<<HTML
            <div class="flex items-center">
              <input
                id="{$field->name}"
                v-model="form.{$field->name}"
                type="checkbox"
                class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
              />
              <label for="{$field->name}" class="ml-2 block text-sm text-gray-900">{$label}</label>
            </div>
HTML;

        return self::renderFormGroup($field, $innerHtml);
    }

    private static function renderDateField(FieldDefinition $field): string
    {
        $type = strtolower($field->type) === 'date' ? 'date' : 'datetime-local';

        return self::buildFormField($field, <<<HTML
            <input
              id="{$field->name}"
              v-model="form.{$field->name}"
              type="{$type}"
              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
            />
HTML);
    }

    private static function needsNullSuffix(FieldDefinition $field, string $type): bool
    {
        return $field->isNullable() && ! str_ends_with($type, '| null');
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
