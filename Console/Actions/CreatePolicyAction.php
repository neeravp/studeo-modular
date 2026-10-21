<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Filesystem\Filesystem;
use Studeo\Modular\Console\Generation\ArtifactWriter;
use Studeo\Modular\Console\Generation\ModuleGuard;
use Studeo\Modular\Console\Generation\NameSanitizer;
use Studeo\Modular\Console\Generation\StubRenderer;
use Studeo\Modular\DataObjects\ClassReference;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Support\PhpImports;
use Studeo\Modular\Support\Schema\ModuleModels;

final class CreatePolicyAction
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly NameSanitizer $sanitizer,
        private readonly StubRenderer $stubRenderer,
        private readonly ModuleGuard $moduleGuard,
        private readonly ArtifactWriter $artifactWriter,
        private readonly ModuleModels $models,
    ) {}

    public function execute(
        ModuleDefinition $module,
        string $name,
        ?string $model = null,
    ): string {
        $policyName = $this->sanitizer->className($name, 'policy', 'Policy');

        $this->moduleGuard->ensureExists($module);
        $this->artifactWriter->ensureDoesNotExist($this->policyPath($module, $policyName), 'Policy', $policyName, $module);

        return $this->createPolicy($module, $policyName, $this->hasModel($model) ? $this->models->resolve($module, (string) $model) : null);
    }

    private function createPolicy(
        ModuleDefinition $module,
        string $policyName,
        ?string $model,
    ): string {
        $path = $this->policyPath($module, $policyName);
        $this->files->ensureDirectoryExists(dirname($path));

        $content = $this->hasModel($model)
            ? $this->buildModelPolicyContent($module, $policyName, (string) $model)
            : $this->buildPlainPolicyContent($module, $policyName);

        $this->files->put($path, $content);

        return $path;
    }

    private function hasModel(?string $model): bool
    {
        return $model !== null && trim($model) !== '';
    }

    private function buildModelPolicyContent(
        ModuleDefinition $module,
        string $policyName,
        string $model,
    ): string {
        $stub = $this->files->get($this->stubRenderer->path('Policy.model.php.stub'));
        [$modelNamespace, $modelClass, $modelVariable] = $this->resolveModel($module, $model);

        $imports = new PhpImports($module->namespace.'\\Policies', $policyName);
        $imports->add(Authenticatable::class);

        return str_replace(
            [
                '{{ namespace }}',
                '{{ class }}',
                '{{ model }}',
                '{{ modelVariable }}',
                '{{ imports }}',
            ],
            [
                $module->namespace.'\\Policies',
                $policyName,
                $imports->name("{$modelNamespace}\\{$modelClass}"),
                $modelVariable,
                $imports->statements(),
            ],
            $stub
        );
    }

    private function buildPlainPolicyContent(
        ModuleDefinition $module,
        string $policyName,
    ): string {
        $stub = $this->files->get($this->stubRenderer->path('Policy.php.stub'));

        return str_replace(
            ['{{ namespace }}', '{{ class }}'],
            [$module->namespace.'\\Policies', $policyName],
            $stub
        );
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function resolveModel(ModuleDefinition $module, string $model): array
    {
        $ref = ClassReference::resolve($module, $model, 'Models');
        $modelVariable = $ref->variable === 'user' ? 'model' : $ref->variable;

        return [$ref->namespace, $ref->class, $modelVariable];
    }

    private function policyPath(ModuleDefinition $module, string $policyName): string
    {
        return $module->path.DIRECTORY_SEPARATOR.'Policies'.DIRECTORY_SEPARATOR."{$policyName}.php";
    }
}
