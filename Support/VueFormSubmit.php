<?php

namespace Studeo\Modular\Support;

use Studeo\Modular\DataObjects\FieldDefinition;

/**
 * The submit function of a generated Vue form. A json field is held as the text the user typed:
 * it is parsed in the browser before the request, a field that will not parse (or a required one
 * left empty) gets an error through setError and no request is sent, and an empty nullable one
 * is sent as null. The parsed values replace the text through form.transform().
 */
final class VueFormSubmit
{
    /**
     * @param  array<int, FieldDefinition>  $fields
     */
    public static function render(array $fields, string $url): string
    {
        $json = array_values(array_filter($fields, FormFieldKinds::isArray(...)));
        $url = addcslashes($url, "'\\");

        if ($json === []) {
            return "const submit = () => {\n  form.post('{$url}')\n}\n";
        }

        return self::parser($json).self::jsonSubmit($json, $url);
    }

    /**
     * @param  array<int, FieldDefinition>  $json
     */
    private static function parser(array $json): string
    {
        $names = implode(' | ', array_map(fn (FieldDefinition $field): string => "'{$field->name}'", $json));

        return <<<TS
            const invalidJson = Symbol()

            const parseJson = (field: {$names}, nullable: boolean): unknown => {
              form.clearErrors(field)
              const label = field.replace(/_/g, ' ')
              const text = form[field].trim()

              if (text === '' && nullable) {
                return null
              }

              if (text === '') {
                form.setError(field, `The \${label} field is required.`)
                return invalidJson
              }

              try {
                return JSON.parse(text)
              } catch {
                form.setError(field, `The \${label} field must be valid JSON.`)
                return invalidJson
              }
            }


            TS;
    }

    /**
     * @param  array<int, FieldDefinition>  $json
     */
    private static function jsonSubmit(array $json, string $url): string
    {
        $entries = implode('', array_map(fn (FieldDefinition $field): string => "    {$field->name}: parseJson('{$field->name}', ".($field->isNullable() ? 'true' : 'false')."),\n", $json));

        return <<<TS
            const submit = () => {
              const json = {
            {$entries}  }

              if (Object.values(json).includes(invalidJson)) {
                return
              }

              form.transform((data) => ({ ...data, ...json })).post('{$url}')
            }

            TS;
    }
}
