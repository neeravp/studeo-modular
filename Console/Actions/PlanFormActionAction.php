<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;
use Studeo\Modular\DataObjects\DtoKind;
use Studeo\Modular\DataObjects\FieldDefinition;
use Studeo\Modular\DataObjects\FormActionPlan;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\SchemaDefinition;
use Studeo\Modular\Support\Schema\DtoProperties;
use Studeo\Modular\Support\Schema\PhpSectionReader;
use Studeo\Modular\Support\Schema\SystemActionMarker;

/**
 * Decides what a form needs from its Action: the Action and its paired Input, which exist, and
 * the fields the form carries. The Input decides the field set: with an existing Input, the
 * context fields are the ones it declares and a schema field it lacks is skipped with a notice.
 * Shared by the Livewire and Vue generators and the Action controller. It writes nothing.
 */
final class PlanFormActionAction
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly ResolveActionNameAction $names,
        private readonly PhpSectionReader $reader,
        private readonly SystemActionMarker $systemActions = new SystemActionMarker,
        private readonly BuildActionPreconditionsAction $preconditions = new BuildActionPreconditionsAction,
    ) {}

    /**
     * @throws RuntimeException
     */
    public function execute(ModuleDefinition $module, SchemaDefinition $schema, string $action): FormActionPlan
    {
        [$actionName, $inputName, $inputPath, $inputExists] = $this->locate($module, $action);

        $inputFields = DtoProperties::fields($schema, DtoKind::Input);
        [$context, $properties] = $inputExists ? $this->existingInput($inputPath, $inputName) : [$this->schemaContext($inputFields), null];
        $this->refuseTooManyChecks($schema, $actionName, "{$module->path}/Actions/{$actionName}.php", $properties);
        [$fields, $notices] = $this->formFields($inputFields, $context, $properties);

        return $this->plan($module, $actionName, $inputName, $inputExists, $fields, $context, $notices);
    }

    /**
     * The plan of a caller with no schema and no fields (a controller): the Action and Input as they
     * stand, the context fields read from the Input when it exists. The caller decides what a
     * missing Action or Input means.
     *
     * @throws RuntimeException
     */
    public function existing(ModuleDefinition $module, string $action): FormActionPlan
    {
        [$actionName, $inputName, $inputPath, $inputExists] = $this->locate($module, $action);

        return $this->plan($module, $actionName, $inputName, $inputExists, [], $inputExists ? $this->existingInput($inputPath, $inputName)[0] : [], []);
    }

    /**
     * Each #[Context] field of the Input needs exactly one --context=field=actor, and actor is the
     * only source.
     *
     * @param  array<int, string>  $options  --context values
     * @param  array<int, string>  $context  the Input's snake_case context fields
     *
     * @throws RuntimeException
     */
    public function checkContext(array $options, array $context): void
    {
        $example = '--context='.($context[0] ?? 'field').'=actor';
        $forms = "Valid: {$example}, where the field is one of the Input's context fields (".($context === [] ? 'none' : implode(', ', $context)).') and actor is the only source.';
        $given = [];

        foreach ($options as $option) {
            [$field, $source] = array_pad(explode('=', $option, 2), 2, null);

            match (true) {
                $source === null => throw new RuntimeException("Context [{$option}] is not a field=actor pair. {$forms}"),
                $source !== 'actor' => throw new RuntimeException("Context source [{$source}] for [{$field}] is not valid; actor is the only source. {$forms}"),
                ! in_array($field, $context, true) => throw new RuntimeException("Context field [{$field}] is not a #[Context] field of the Input. {$forms}"),
                default => $given[] = $field,
            };
        }

        $missing = array_values(array_diff($context, $given));

        if ($missing !== []) {
            throw new RuntimeException('Context '.(count($missing) > 1 ? 'fields' : 'field').' ['.implode(', ', $missing).'] '.(count($missing) > 1 ? 'need' : 'needs')." a source. {$forms}");
        }
    }

    /**
     * The Action's and Input's names and paths, with a system Action refused.
     *
     * @return array{0: string, 1: string, 2: string, 3: bool} action name, Input name, Input path, whether the Input exists
     *
     * @throws RuntimeException
     */
    private function locate(ModuleDefinition $module, string $action): array
    {
        $actionName = $this->names->execute($action);
        $inputName = $this->names->pairedInput($actionName);
        $inputPath = "{$module->path}/Data/{$inputName}.php";

        $this->refuseSystemAction($actionName, "{$module->path}/Actions/{$actionName}.php");

        return [$actionName, $inputName, $inputPath, $this->files->isFile($inputPath)];
    }

    /**
     * @param  array<int, FieldDefinition>  $fields
     * @param  array<int, string>  $context
     * @param  array<int, string>  $notices
     */
    private function plan(ModuleDefinition $module, string $actionName, string $inputName, bool $inputExists, array $fields, array $context, array $notices): FormActionPlan
    {
        return new FormActionPlan(
            actionName: $actionName,
            inputName: $inputName,
            actionClass: $module->namespace.'\\Actions\\'.str_replace('/', '\\', $actionName),
            inputClass: $module->namespace.'\\Data\\'.str_replace('/', '\\', $inputName),
            actionExists: $this->files->isFile("{$module->path}/Actions/{$actionName}.php"),
            inputExists: $inputExists,
            fields: $fields,
            context: $context,
            notices: $notices,
        );
    }

    /**
     * A system Action is never referenced from a form or a controller, so an existing
     * one is refused before anything is written.
     *
     * @throws RuntimeException
     */
    private function refuseSystemAction(string $actionName, string $actionPath): void
    {
        if ($this->isSystemAction($actionPath)) {
            throw new RuntimeException('Action ['.class_basename($actionName).'] is a #[SystemAction], and system Actions are not callable from forms or controllers. Choose an Action that authorizes; nothing was written.');
        }
    }

    private function isSystemAction(string $actionPath): bool
    {
        return $this->files->isFile($actionPath) && $this->systemActions->isMarked((string) file_get_contents($actionPath));
    }

    /**
     * An Action about to be generated holds its checks in guard groups, and a group has a limit.
     *
     * @param  array<int, string>|null  $properties
     *
     * @throws RuntimeException
     */
    private function refuseTooManyChecks(SchemaDefinition $schema, string $actionName, string $actionPath, ?array $properties): void
    {
        if ($this->files->isFile($actionPath)) {
            return;
        }

        $this->preconditions->ensureWithinLimit($schema, $actionName, $properties);
    }

    /**
     * @param  array<int, FieldDefinition>  $inputFields
     * @return array<int, string>
     */
    private function schemaContext(array $inputFields): array
    {
        return array_values(array_map(fn (FieldDefinition $field): string => $field->name, array_filter($inputFields, fn (FieldDefinition $field): bool => $field->isContext())));
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private function existingInput(string $path, string $inputName): array
    {
        $source = (string) file_get_contents($path);
        $parameters = $this->reader->constructorParameters($source);

        if ($parameters === null) {
            throw new RuntimeException("Input [{$inputName}] exists but its constructor cannot be read; nothing was written.");
        }

        return [array_map(fn (string $name): string => Str::snake($name), $this->reader->contextParameters($source) ?? []), array_keys($parameters)];
    }

    /**
     * The form carries the Input's fields except the context ones; an existing Input may lack some.
     *
     * @param  array<int, FieldDefinition>  $inputFields
     * @param  array<int, string>  $context
     * @param  array<int, string>|null  $properties
     * @return array{0: array<int, FieldDefinition>, 1: array<int, string>}
     */
    private function formFields(array $inputFields, array $context, ?array $properties): array
    {
        $carried = array_filter($inputFields, fn (FieldDefinition $field): bool => ! in_array($field->name, $context, true));
        $kept = array_filter($carried, fn (FieldDefinition $field): bool => $this->isInInput($field, $properties));
        $skipped = array_map(fn (FieldDefinition $field): string => "Skipped form field [{$field->name}]: the existing Input has no property [{$this->propertyOf($field)}].", array_diff_key($carried, $kept));

        return [array_values($kept), array_values($skipped)];
    }

    /**
     * @param  array<int, string>|null  $properties
     */
    private function isInInput(FieldDefinition $field, ?array $properties): bool
    {
        return $properties === null || in_array($this->propertyOf($field), $properties, true);
    }

    private function propertyOf(FieldDefinition $field): string
    {
        return lcfirst(Str::studly($field->name));
    }
}
