<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Studeo\Modular\Console\Generation\ArtifactWriter;
use Studeo\Modular\Console\Generation\ModuleGuard;
use Studeo\Modular\Console\Generation\StubRenderer;
use Studeo\Modular\DataObjects\ActionGenerationOptions;
use Studeo\Modular\DataObjects\ActionGenerationResult;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\SchemaDefinition;
use Studeo\Modular\Support\Attributes\SystemAction;
use Studeo\Modular\Support\PhpImports;
use Studeo\Modular\Support\Schema\PhpSectionReader;
use Studeo\Modular\Support\SchemaWarnings;

final class CreateActionAction
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly ResolveActionNameAction $names,
        private readonly CreateDtoAction $dtos,
        private readonly ResolveActionAuthorizationAction $authorization,
        private readonly SchemaWarnings $warnings,
        private readonly StubRenderer $stubRenderer,
        private readonly ModuleGuard $moduleGuard,
        private readonly ArtifactWriter $artifactWriter,
        private readonly ParseSchemaAction $schemaParser = new ParseSchemaAction,
        private readonly BuildActionPreconditionsAction $preconditions = new BuildActionPreconditionsAction,
        private readonly PhpSectionReader $reader = new PhpSectionReader,
    ) {}

    /**
     * Creates the Action and, when it does not exist yet, the Input that pairs with it.
     * An existing Input is used as it is and never overwritten.
     */
    public function execute(
        ModuleDefinition $module,
        string $name,
        ?ActionGenerationOptions $options = null,
    ): ActionGenerationResult {
        $actionName = $this->ensureCreatable($module, $name);
        $inputName = $this->names->pairedInput($actionName);
        $actionPath = $this->path($module, 'Actions', $actionName);
        $options ??= new ActionGenerationOptions;
        $input = $this->pairInput($module, $actionName, $inputName, $options);

        $this->files->ensureDirectoryExists(dirname($actionPath));
        $this->files->put($actionPath, $this->content($module, $actionName, $inputName, $options, $input['checks'], $input['guards']));

        return new ActionGenerationResult($actionPath, $input['path'], $input['created'], $input['notices']);
    }

    /**
     * The Action's name, once the module exists and the Action does not.
     */
    private function ensureCreatable(ModuleDefinition $module, string $name): string
    {
        $this->moduleGuard->ensureExists($module);

        $actionName = $this->names->execute($name);
        $this->artifactWriter->ensureDoesNotExist($this->path($module, 'Actions', $actionName), 'Action', $actionName, $module);

        return $actionName;
    }

    /**
     * Builds the checks from the schema and the Input, generating the Input first when it is missing.
     *
     * @return array{path: string, created: bool, checks: string, guards: string, notices: array<int, string>}
     */
    private function pairInput(ModuleDefinition $module, string $actionName, string $inputName, ActionGenerationOptions $options): array
    {
        $inputPath = $this->path($module, 'Data', $inputName);
        $schema = $options->schemaDefinition ?? $this->schemaParser->execute($options->schema, $module)->forModule($module->namespace);
        $created = ! $this->files->exists($inputPath);
        $checks = $this->checks($module, $schema, $actionName, $created ? null : $inputPath);

        if ($created) {
            $this->dtos->execute($module, $inputName, $schema);
        }

        return ['path' => $inputPath, 'created' => $created, ...$checks];
    }

    /**
     * @return array{checks: string, guards: string, notices: array<int, string>}
     */
    private function checks(ModuleDefinition $module, SchemaDefinition $schema, string $actionName, ?string $existingInput): array
    {
        if (! $schema->hasFields()) {
            return ['checks' => '', 'guards' => '', 'notices' => []];
        }

        if (! $this->preconditions->applies($actionName)) {
            return ['checks' => '', 'guards' => '', 'notices' => ['Preconditions are only generated for Create* Actions; none were added.']];
        }

        $properties = $existingInput === null ? null : array_keys($this->reader->constructorParameters((string) file_get_contents($existingInput)) ?? []);

        return $this->preconditions->execute($schema, $this->preconditions->table($schema, $actionName), $properties);
    }

    private function content(ModuleDefinition $module, string $actionName, string $inputName, ActionGenerationOptions $options, string $checks = '', string $guards = ''): string
    {
        $namespace = $module->namespace.'\\Actions'.$this->subNamespace($actionName);
        $imports = new PhpImports($namespace, class_basename($actionName));
        $imports->add(DB::class, ...($checks === '' ? [] : [ValidationException::class]));
        $variant = $this->variant($module, $options, $actionName, $imports);
        $input = $imports->name($module->namespace.'\\Data'.$this->subNamespace($inputName).'\\'.class_basename($inputName));

        $replacements = [
            'namespace' => $namespace,
            'class' => class_basename($actionName),
            'input' => $input,
            'guards' => str_replace('{{ input }}', $input, $guards),
            'transaction' => $checks === '' ? 'function (): void {}' : "function () use (\$input): void {\n{$checks}        }",
            ...$variant,
        ];
        $replacements['imports'] = $imports->statements();

        return str_replace(
            array_map(fn (string $key): string => '{{ '.$key.' }}', array_keys($replacements)),
            array_values($replacements),
            $this->files->get($this->stubRenderer->path('action.stub')),
        );
    }

    /**
     * The parts of the stub that differ for a system Action, which has no actor and no Gate, from
     * one that authorizes, with the classes they name registered in the imports. The authorization
     * notice, if any, goes to stderr with the schema warnings.
     *
     * @return array<string, string>
     */
    private function variant(ModuleDefinition $module, ActionGenerationOptions $options, string $actionName, PhpImports $imports): array
    {
        if ($options->system) {
            $imports->add(SystemAction::class);

            return [
                'actor_param' => '',
                'docblock' => "/**\n * System action: callable only from Actions, Listeners and Jobs.\n */\n",
                'attribute' => "#[SystemAction]\n",
                'authorize' => '',
            ];
        }

        $authorization = $this->authorization->execute($module, $actionName);

        if ($authorization->notice !== null) {
            $this->warnings->add($authorization->notice);
        }

        $imports->add(Authenticatable::class, Gate::class);

        return [
            'actor_param' => ', Authenticatable $actor',
            'docblock' => '',
            'attribute' => '',
            'authorize' => str_replace('{{ model }}', $authorization->modelClass === '' ? '' : $imports->name($authorization->modelClass), $authorization->call),
        ];
    }

    private function subNamespace(string $name): string
    {
        $segments = array_slice(explode('/', $name), 0, -1);

        return $segments === [] ? '' : '\\'.implode('\\', $segments);
    }

    private function path(ModuleDefinition $module, string $directory, string $name): string
    {
        return $module->path.DIRECTORY_SEPARATOR.$directory.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $name).'.php';
    }
}
