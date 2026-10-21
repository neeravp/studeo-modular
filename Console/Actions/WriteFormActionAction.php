<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use Studeo\Modular\DataObjects\ActionGenerationOptions;
use Studeo\Modular\DataObjects\FormActionPlan;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\SchemaDefinition;

/**
 * Writes the Action and Input a planned form saves through, and leaves alone what exists. A
 * missing Action comes from the same generator as module:action (so the Create* preconditions
 * come with it), a missing Input from the one behind module:dto. Shared by every form generator.
 */
final class WriteFormActionAction
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly CreateActionAction $actions,
        private readonly CreateDtoAction $dtos,
    ) {}

    /**
     * @return array{created: array<int, string>, reused: array<int, string>, notices: array<int, string>}
     */
    public function execute(ModuleDefinition $module, FormActionPlan $plan, SchemaDefinition $schema): array
    {
        $written = $plan->actionExists
            ? $this->existingAction($module, $plan, $schema)
            : $this->newAction($module, $plan, $schema);

        return [...$written, 'notices' => [...$plan->notices, ...$written['notices']]];
    }

    /**
     * @return array{created: array<int, string>, reused: array<int, string>, notices: array<int, string>}
     */
    private function newAction(ModuleDefinition $module, FormActionPlan $plan, SchemaDefinition $schema): array
    {
        $result = $this->actions->execute($module, $plan->actionName, new ActionGenerationOptions(schemaDefinition: $schema));

        return [
            'created' => $result->inputCreated ? [$result->actionPath, $result->inputPath] : [$result->actionPath],
            'reused' => $result->inputCreated ? [] : [$result->inputPath],
            'notices' => $result->notices,
        ];
    }

    /**
     * @return array{created: array<int, string>, reused: array<int, string>, notices: array<int, string>}
     */
    private function existingAction(ModuleDefinition $module, FormActionPlan $plan, SchemaDefinition $schema): array
    {
        $actionPath = "{$module->path}/Actions/{$plan->actionName}.php";
        $input = $plan->inputExists ? null : $this->dtos->execute($module, $plan->inputName, $schema);

        return [
            'created' => $input === null ? [] : [$input],
            'reused' => $input === null ? [$actionPath, "{$module->path}/Data/{$plan->inputName}.php"] : [$actionPath],
            'notices' => [],
        ];
    }
}
