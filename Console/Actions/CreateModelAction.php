<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use Studeo\Modular\Console\Generation\ArtifactWriter;
use Studeo\Modular\Console\Generation\ModuleGuard;
use Studeo\Modular\Console\Generation\StubRenderer;
use Studeo\Modular\DataObjects\MigrationGenerationOptions;
use Studeo\Modular\DataObjects\ModelGenerationOptions;
use Studeo\Modular\DataObjects\ModelGenerationResult;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\SchemaDefinition;
use Studeo\Modular\Support\ModelFileContent;
use Studeo\Modular\Support\Schema\ModuleModels;
use Studeo\Modular\Support\Schema\SchemaFiles;

final class CreateModelAction
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly CreateMigrationAction $migrationCreator,
        private readonly CreateFactoryAction $factoryCreator,
        private readonly CreateSeederAction $seederCreator,
        private readonly ParseSchemaAction $schemaParser,
        private readonly ResolveModelNameAction $names,
        private readonly PlanModelAction $plan,
        private readonly StubRenderer $stubRenderer,
        private readonly ModuleGuard $moduleGuard,
        private readonly ArtifactWriter $artifactWriter,
    ) {}

    public function execute(
        ModuleDefinition $module,
        string $name,
        ?ModelGenerationOptions $options = null,
    ): ModelGenerationResult {
        $options ??= new ModelGenerationOptions;
        $modelName = $this->names->execute($name);

        $this->moduleGuard->ensureExists($module);

        $schema = $this->schemaParser->execute($options->schemaPath, $module)->forModule($module->namespace)->forModels(ModuleModels::names($module));
        $tableName = $this->resolveTableName($schema, $modelName);

        $this->plan->execute($module, $modelName, $tableName, $options);

        return new ModelGenerationResult(
            modelPath: $this->createModel($module, $modelName, $tableName, $schema, $options->withFactory),
            migrationPath: $this->createMigrationIfRequested($module, $tableName, $schema, $options->withMigration),
            factoryPath: $this->createFactoryIfRequested($module, $modelName, $schema, $options->withFactory),
            seederPath: $this->createSeederIfRequested($module, $modelName, $options->withSeeder),
        );
    }

    private function resolveTableName(SchemaDefinition $schema, string $modelName): string
    {
        return $schema->table ?? $this->defaultTableName($modelName);
    }

    private function defaultTableName(string $modelName): string
    {
        return SchemaFiles::tableFor($modelName);
    }

    private function createModel(
        ModuleDefinition $module,
        string $modelName,
        string $tableName,
        SchemaDefinition $schema,
        bool $withFactory,
    ): string {
        $path = $this->modelPath($module, $modelName);
        $factoryClass = $this->isFactoryWired($module, $modelName, $withFactory) ? "{$module->namespace}\\Database\\Factories\\{$modelName}Factory" : null;
        $content = ModelFileContent::render($this->files->get($this->stubRenderer->path('Model.php.stub')), "{$module->namespace}\\Models", $modelName, $tableName, $schema, $factoryClass);

        $this->artifactWriter->write($path, $content);

        return $path;
    }

    /**
     * The model is wired to its factory when the factory is generated with it or already exists.
     */
    private function isFactoryWired(ModuleDefinition $module, string $modelName, bool $withFactory): bool
    {
        return $withFactory || ModuleModels::hasFactory($module, $modelName);
    }

    private function createMigrationIfRequested(
        ModuleDefinition $module,
        string $tableName,
        SchemaDefinition $schema,
        bool $requested,
    ): ?string {
        if (! $requested) {
            return null;
        }

        return $this->createMigration($module, $tableName, $schema);
    }

    private function createFactoryIfRequested(
        ModuleDefinition $module,
        string $modelName,
        SchemaDefinition $schema,
        bool $requested,
    ): ?string {
        if (! $requested) {
            return null;
        }

        return $this->createFactory($module, $modelName, $schema);
    }

    private function createSeederIfRequested(
        ModuleDefinition $module,
        string $modelName,
        bool $requested,
    ): ?string {
        if (! $requested) {
            return null;
        }

        return $this->createSeeder($module, $modelName);
    }

    private function createMigration(
        ModuleDefinition $module,
        string $tableName,
        SchemaDefinition $schema,
    ): string {
        $result = $this->migrationCreator->execute(
            $module,
            "create_{$tableName}_table",
            new MigrationGenerationOptions(
                create: $tableName,
                schema: $schema,
            ),
        );

        return $result->path;
    }

    private function createFactory(
        ModuleDefinition $module,
        string $modelName,
        SchemaDefinition $schema,
    ): string {
        return $this->factoryCreator->execute(
            module: $module,
            name: $modelName,
            model: null,
            schema: $schema,
        );
    }

    private function createSeeder(ModuleDefinition $module, string $modelName): string
    {
        return $this->seederCreator->execute($module, $modelName, $modelName);
    }

    private function modelPath(ModuleDefinition $module, string $modelName): string
    {
        return $this->plan->modelPath($module, $modelName);
    }
}
