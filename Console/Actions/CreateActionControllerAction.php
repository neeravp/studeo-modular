<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Support\Str;
use RuntimeException;
use Studeo\Modular\DataObjects\ControllerActionPlan;
use Studeo\Modular\DataObjects\ControllerGenerationOptions;
use Studeo\Modular\DataObjects\ControllerGenerationResult;
use Studeo\Modular\DataObjects\ModuleDefinition;

/**
 * module:controller --action: plans the Action, checks the controller can be written, then writes
 * the Action and Input a --schema asks for (through the forms' writer) and the controller. Nothing
 * is written when any check fails. The controller is named after the Action unless a name is given.
 */
final class CreateActionControllerAction
{
    public function __construct(
        private readonly CreateControllerAction $controllers,
        private readonly PlanControllerActionAction $planner,
        private readonly WriteFormActionAction $writer,
        private readonly ResolveActionNameAction $names,
    ) {}

    /**
     * @throws RuntimeException
     */
    public function execute(ModuleDefinition $module, ?string $name, ControllerGenerationOptions $options): ControllerGenerationResult
    {
        $controllerName = $this->controllers->ensureCreatable($module, $name ?? Str::beforeLast(class_basename($this->names->execute((string) $options->action)), 'Action'));
        $plan = $this->planner->execute($module, $options);

        $written = $plan->schema === null ? $this->reused($module, $plan) : $this->writer->execute($module, $plan->action, $plan->schema);
        $path = $this->controllers->execute($module, $controllerName, $options, $plan->action);

        return new ControllerGenerationResult($path, $module->name, $written['created'], $written['reused'], $written['notices'], $this->route($module, $controllerName, $plan->url));
    }

    /**
     * @return array{created: array<int, string>, reused: array<int, string>, notices: array<int, string>}
     */
    private function reused(ModuleDefinition $module, ControllerActionPlan $plan): array
    {
        return [
            'created' => [],
            'reused' => ["{$module->path}/Actions/{$plan->action->actionName}.php", "{$module->path}/Data/{$plan->action->inputName}.php"],
            'notices' => $plan->action->notices,
        ];
    }

    private function route(ModuleDefinition $module, string $controllerName, ?string $url): ?string
    {
        return $url === null ? null : "Route::post('{$url}', \\{$module->namespace}\\Controllers\\{$controllerName}::class);";
    }
}
