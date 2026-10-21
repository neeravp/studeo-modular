<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;
use Studeo\Modular\Console\Generation\ArtifactWriter;
use Studeo\Modular\Console\Generation\ModuleGuard;
use Studeo\Modular\Console\Generation\NameSanitizer;
use Studeo\Modular\Console\Generation\StubRenderer;
use Studeo\Modular\DataObjects\ControllerGenerationOptions;
use Studeo\Modular\DataObjects\FormActionPlan;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Enums\ControllerType;
use Studeo\Modular\Support\PhpImports;
use Studeo\Modular\Support\Schema\ModuleModels;

final class CreateControllerAction
{
    private const string SUFFIX = 'Controller';

    public function __construct(
        private readonly Filesystem $files,
        private readonly NameSanitizer $sanitizer,
        private readonly StubRenderer $stubRenderer,
        private readonly ModuleGuard $moduleGuard,
        private readonly ArtifactWriter $artifactWriter,
    ) {}

    /**
     * Writes the controller; with an Action plan, the invokable that calls that Action.
     *
     * @throws RuntimeException
     */
    public function execute(
        ModuleDefinition $module,
        string $name,
        ?ControllerGenerationOptions $options = null,
        ?FormActionPlan $action = null,
    ): string {
        $options ??= new ControllerGenerationOptions;
        $controllerName = $this->ensureCreatable($module, $name);
        $path = $this->controllerPath($module, $controllerName);

        $model = $options->model === null ? null : ModuleModels::resolve($module, $options->model);

        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $this->content($module, $controllerName, $options, $model, $action));

        return $path;
    }

    /**
     * The controller's class name, once the module exists and the controller does not.
     *
     * @throws RuntimeException
     */
    public function ensureCreatable(ModuleDefinition $module, string $name): string
    {
        $controllerName = $this->sanitizer->className($name, 'controller', self::SUFFIX);

        if ($controllerName === self::SUFFIX) {
            throw new RuntimeException("Controller name [{$name}] is only Controller; give it a name, e.g. Flight or FlightController.");
        }

        $this->moduleGuard->ensureExists($module);
        $this->artifactWriter->ensureDoesNotExist($this->controllerPath($module, $controllerName), 'Controller', $controllerName, $module);

        return $controllerName;
    }

    private function content(ModuleDefinition $module, string $controllerName, ControllerGenerationOptions $options, ?string $model, ?FormActionPlan $action): string
    {
        $imports = new PhpImports($module->namespace.'\\Controllers', $controllerName);
        $imports->add(Request::class);
        if ($options->inertia) {
            $imports->add('Inertia\\Inertia');
        }
        $binding = $model === null ? 'string $id' : $imports->name("{$module->namespace}\\Models\\{$model}").' $'.Str::camel($model);

        $replacements = [
            '{{ namespace }}' => $module->namespace.'\\Controllers',
            '{{ class }}' => $controllerName,
            '{{ module }}' => $module->name,
            '{{ resource }}' => Str::beforeLast($controllerName, self::SUFFIX),
            '{{ binding }}' => $binding,
            '{{ request }}' => $this->requestVariable($model),
            ...($action === null ? [] : $this->actionReplacements($action, $imports)),
        ];
        $content = str_replace(array_keys($replacements), array_values($replacements), $this->files->get($this->stubRenderer->path($this->stub($options, $action))));

        return str_replace('{{ imports }}', $imports->statements(), $content);
    }

    /**
     * The injected request is `$httpRequest` when the bound model's own variable would be `$request`.
     */
    private function requestVariable(?string $model): string
    {
        return $model !== null && Str::camel($model) === 'request' ? '$httpRequest' : '$request';
    }

    /**
     * The Action and Input by their short names; the context array only when the Input has
     * context fields, each taken from the actor.
     *
     * @return array<string, string>
     */
    private function actionReplacements(FormActionPlan $action, PhpImports $imports): array
    {
        $context = array_map(fn (string $field): string => "            '{$field}' => \$actor->getAuthIdentifier(),\n", $action->context);

        return [
            '{{ action }}' => $imports->name($action->actionClass),
            '{{ input }}' => $imports->name($action->inputClass),
            '{{ context }}' => $context === [] ? '' : ", [\n".implode('', $context).'        ]',
        ];
    }

    private function stub(ControllerGenerationOptions $options, ?FormActionPlan $action): string
    {
        return $action !== null ? 'Controller.action.php.stub' : match ($options->type) {
            ControllerType::Invokable => $options->inertia ? 'Controller.inertia.invokable.php.stub' : 'Controller.invokable.php.stub',
            ControllerType::Resource => $options->inertia ? 'Controller.inertia.php.stub' : 'Controller.resource.php.stub',
            ControllerType::Api => 'Controller.api.php.stub',
            ControllerType::Standard => 'Controller.standard.php.stub',
        };
    }

    private function controllerPath(ModuleDefinition $module, string $controllerName): string
    {
        return $module->path.DIRECTORY_SEPARATOR.'Controllers'.DIRECTORY_SEPARATOR."{$controllerName}.php";
    }
}
