<?php

namespace Studeo\Modular\Support;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;
use Livewire\Form;
use Studeo\Modular\DataObjects\FieldDefinition;
use Studeo\Modular\DataObjects\LivewireFormPlan;

/**
 * The text of a generated Livewire form: the Form class that delegates its rules to the Input,
 * and the body of the component that saves it through the Action.
 */
final class LivewireFormContent
{
    public static function form(string $stub, LivewireFormPlan $plan): string
    {
        $nullable = array_filter($plan->action->fields, fn (FieldDefinition $field): bool => $field->isNullable());
        $json = array_filter($plan->action->fields, FormFieldKinds::isArray(...));

        $namespace = Str::beforeLast($plan->formClass, '\\');
        $imports = new PhpImports($namespace, class_basename($plan->formClass));
        $imports->add(ValidationException::class, Form::class, ...($json === [] ? [] : [JsonException::class]));
        $input = $imports->name($plan->action->inputClass);

        return str_replace(
            ['{{ namespace }}', '{{ class }}', '{{ input }}', '{{ nullable }}', '{{ properties }}', ...array_keys(self::jsonParts([])), '{{ imports }}'],
            [
                $namespace,
                class_basename($plan->formClass),
                $input,
                self::quotedNames($nullable),
                implode("\n\n", array_map(fn (FieldDefinition $field): string => '    public '.LivewireClassResolver::resolvePropertyType($field).' $'.$field->name.' = '.LivewireClassResolver::resolvePropertyDefault($field).';', $plan->action->fields)),
                ...array_values(self::jsonParts($json)),
                $imports->statements(),
            ],
            $stub,
        );
    }

    /**
     * A json field is held as the text the user typed. Livewire's prepareForValidation() hook
     * decodes it before the Input's rules run, and toInput() reuses the hook, so the Input gets
     * arrays; empty text is null and text that will not decode is an error on that field.
     *
     * @param  array<int, FieldDefinition>  $json
     * @return array<string, string>
     */
    private static function jsonParts(array $json): array
    {
        $parts = ['{{ json_constant }}' => '', '{{ json_methods }}' => '', '{{ state }}' => '$this->all()'];

        if ($json === []) {
            return $parts;
        }

        $names = self::quotedNames($json);

        return ['{{ json_constant }}' => "\n    public const array JSON = [{$names}];\n", '{{ json_methods }}' => <<<'PHP'

                protected function prepareForValidation($attributes): array
                {
                    foreach (self::JSON as $field) {
                        try {
                            $attributes[$field] = trim((string) $attributes[$field]) === '' ? null : json_decode($attributes[$field], true, flags: JSON_THROW_ON_ERROR);
                        } catch (JsonException) {
                            throw ValidationException::withMessages([$field => 'The '.str_replace('_', ' ', $field).' field must be valid JSON.']);
                        }
                    }

                    return $attributes;
                }

            PHP, '{{ state }}' => '$this->prepareForValidation($this->all())'];
    }

    /**
     * @param  array<int, FieldDefinition>  $fields
     */
    private static function quotedNames(array $fields): string
    {
        return implode(', ', array_map(fn (FieldDefinition $field): string => "'{$field->name}'", $fields));
    }

    /**
     * The component's state is its Form; save() validates it, saves through the Action as the
     * authenticated user, shows the Action's own validation errors on the form, and resets.
     */
    public static function componentBody(LivewireFormPlan $plan): string
    {
        $pairs = implode(', ', array_map(fn (string $key): string => "'{$key}' => \$actor->getAuthIdentifier()", $plan->action->context));
        $context = $pairs === '' ? '' : "[{$pairs}]";
        $form = class_basename($plan->formClass);
        $action = class_basename($plan->action->actionClass);

        return <<<PHP
            public {$form} \$form;

            public function save(): void
            {
                \$this->form->validate();

                \$actor = auth()->user() ?? abort(401);

                try {
                    app({$action}::class)(\$this->form->toInput({$context}), \$actor);
                } catch (ValidationException \$exception) {
                    \$this->form->addActionErrors(\$exception);

                    return;
                }

                \$this->form->reset();
            }
        PHP;
    }

    /**
     * @return array<int, string>
     */
    public static function componentImports(LivewireFormPlan $plan): array
    {
        return ['Illuminate\Validation\ValidationException', $plan->action->actionClass, $plan->formClass];
    }
}
