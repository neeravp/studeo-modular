<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Studeo\Modular\Console\Generation\ArtifactWriter;
use Studeo\Modular\Console\Generation\ModuleGuard;
use Studeo\Modular\Console\Generation\NameSanitizer;
use Studeo\Modular\Console\Generation\StubRenderer;
use Studeo\Modular\DataObjects\ClassReference;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\SchemaDefinition;
use Studeo\Modular\DataObjects\SchemaIssue;
use Studeo\Modular\Support\FakerDefinitionResolver;
use Studeo\Modular\Support\PhpImports;
use Studeo\Modular\Support\Schema\ForeignKeyFaker;
use Studeo\Modular\Support\Schema\ModuleModels;
use Studeo\Modular\Support\SchemaWarnings;

final class CreateFactoryAction
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly SchemaWarnings $warnings,
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
        ?SchemaDefinition $schema = null,
    ): string {
        $this->moduleGuard->ensureExists($module);

        $factoryName = $this->sanitizer->className($name, 'factory', 'Factory');
        $this->artifactWriter->ensureDoesNotExist($this->factoryPath($module, $factoryName), 'Factory', $factoryName, $module);

        $modelDefinition = $this->resolveModelDefinition($module, $factoryName, $model);

        return $this->createFactoryFile($module, $factoryName, $modelDefinition, $schema);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveModelDefinition(
        ModuleDefinition $module,
        string $factoryName,
        ?string $model,
    ): array {
        $modelInput = filled($model)
            ? trim($model)
            : $this->guessModelFromFactoryName($factoryName);

        return $this->resolveModel($module, $modelInput);
    }

    private function guessModelFromFactoryName(string $factoryName): string
    {
        $guessed = Str::replaceLast('Factory', '', $factoryName);

        return $guessed !== '' ? $guessed : $factoryName;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveModel(ModuleDefinition $module, string $model): array
    {
        $ref = ClassReference::resolve($module, $model, 'Models');
        $this->sanitizer->ensureStartsWithLetter($ref->class, $model, 'model');

        return [
            $ref->namespace,
            $ref->class,
        ];
    }

    /**
     * @param  array{0: string, 1: string}  $modelDefinition
     */
    private function createFactoryFile(
        ModuleDefinition $module,
        string $factoryName,
        array $modelDefinition,
        ?SchemaDefinition $schema,
    ): string {
        $path = $this->factoryPath($module, $factoryName);
        $this->files->ensureDirectoryExists(dirname($path));

        $content = $this->buildFactoryContent($module, $factoryName, $modelDefinition, $schema);

        $this->files->put($path, $content);

        return $path;
    }

    /**
     * @param  array{0: string, 1: string}  $modelDefinition
     */
    private function buildFactoryContent(
        ModuleDefinition $module,
        string $factoryName,
        array $modelDefinition,
        ?SchemaDefinition $schema,
    ): string {
        [$modelNamespace, $modelClass] = $modelDefinition;
        $factoryNamespace = $module->namespace.'\\Database\\Factories';
        $imports = new PhpImports($factoryNamespace, $factoryName);

        $replacements = [
            '{{ factoryNamespace }}' => $factoryNamespace,
            '{{ factory }}' => $factoryName,
            '{{ base }}' => $imports->name('Illuminate\\Database\\Eloquent\\Factories\\Factory'),
            '{{ model }}' => $imports->name("{$modelNamespace}\\{$modelClass}"),
            '{{ definition }}' => $this->definitionArray($schema === null ? [] : $this->definition($module, $schema, $imports)),
        ];

        return str_replace(
            [...array_keys($replacements), '{{ imports }}'],
            [...array_values($replacements), $imports->statements()],
            $this->files->get($this->stubRenderer->path('Factory.php.stub'))
        );
    }

    /**
     * The returned array: `[]` when there is nothing to fake, otherwise one attribute per line.
     *
     * @param  array<string, string>  $attributes
     */
    private function definitionArray(array $attributes): string
    {
        return $attributes === []
            ? '[]'
            : "[\n".implode('', array_map(fn (string $faker, string $field): string => "            '{$field}' => {$faker},\n", $attributes, array_keys($attributes))).'        ]';
    }

    /**
     * @return array<string, string>
     */
    private function definition(ModuleDefinition $module, SchemaDefinition $schema, PhpImports $imports): array
    {
        $definition = FakerDefinitionResolver::definition($schema, new ForeignKeyFaker($this->models, $module, config('auth.providers.users.model'), $imports), $imports);

        foreach ($definition->omitted as $column => $reason) {
            $this->warnings->add(SchemaIssue::warning("fields.{$column}", 'factory_foreign_key', $reason));
        }

        return $definition->attributes;
    }

    public function factoryPath(ModuleDefinition $module, string $factoryName): string
    {
        return $module->path.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'factories'.DIRECTORY_SEPARATOR."{$factoryName}.php";
    }
}
