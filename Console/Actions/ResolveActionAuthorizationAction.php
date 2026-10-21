<?php

namespace Studeo\Modular\Console\Actions;

use Studeo\Modular\DataObjects\ActionAuthorization;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\SchemaIssue;
use Studeo\Modular\Support\Schema\ModuleModels;

/**
 * Decides what the Gate call of a generated Action authorizes. A Create{X} Action whose model X is
 * in the module authorizes 'create' against the model class, which Laravel resolves to the model's
 * policy. Any other Action authorizes its kebab ability against the Input, which no policy owns, so
 * it fails closed until the ability is defined or the Action loads the model and authorizes the instance.
 */
final class ResolveActionAuthorizationAction
{
    private const string VERB = 'Create';

    public function __construct(
        private readonly ResolveActionNameAction $names,
    ) {}

    public function execute(ModuleDefinition $module, string $actionName): ActionAuthorization
    {
        $model = $this->createdModel($actionName);

        if ($this->isModelOf($module, $model)) {
            return new ActionAuthorization(
                "{$module->namespace}\\Models\\{$model}",
                "        Gate::forUser(\$actor)->authorize('create', {{ model }}::class);\n\n",
            );
        }

        $ability = $this->names->ability($actionName);
        $reason = $model === null
            ? "Action [{$actionName}] authorizes the ability [{$ability}] against its Input"
            : "Action [{$actionName}] has no Models/{$model}.php in module [{$module->name}] to authorize 'create' against, so it authorizes the ability [{$ability}] against its Input";

        return new ActionAuthorization(
            '',
            "        Gate::forUser(\$actor)->authorize('{$ability}', \$input);\n\n",
            SchemaIssue::warning("actions.{$actionName}", 'action_ability', "{$reason}. That fails closed until the ability is defined, or the Action loads the model and authorizes against the instance."),
        );
    }

    /**
     * The model a Create{X} Action creates: the studly name after Create, whatever folder the Action is in.
     */
    private function createdModel(string $actionName): ?string
    {
        $name = substr(class_basename($actionName), 0, -strlen('Action'));
        $model = str_starts_with($name, self::VERB) ? substr($name, strlen(self::VERB)) : '';

        return $this->isModelName($model) ? $model : null;
    }

    private function isModelOf(ModuleDefinition $module, ?string $model): bool
    {
        return $model !== null && in_array($model, ModuleModels::names($module), true);
    }

    private function isModelName(string $model): bool
    {
        return $model !== '' && ctype_upper($model[0]);
    }
}
