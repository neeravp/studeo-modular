<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Studeo\Modular\Console\Generation\ArtifactWriter;
use Studeo\Modular\Console\Generation\ModuleGuard;
use Studeo\Modular\Console\Generation\StubRenderer;
use Studeo\Modular\DataObjects\MigrationGenerationOptions;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\PivotDefinition;
use Studeo\Modular\DataObjects\PivotGenerationOptions;
use Studeo\Modular\DataObjects\PivotGenerationResult;

final class CreatePivotAction
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly ResolvePivotEntitiesAction $entitiesResolver,
        private readonly CreateMigrationAction $migrationCreator,
        private readonly StubRenderer $stubRenderer,
        private readonly ModuleGuard $moduleGuard,
        private readonly ArtifactWriter $artifactWriter,
    ) {}

    public function execute(
        ModuleDefinition $module,
        string $model1,
        ?string $model2 = null,
        ?PivotGenerationOptions $options = null,
    ): PivotGenerationResult {
        $options ??= new PivotGenerationOptions;

        $this->moduleGuard->ensureExists($module);

        $pivot = $this->resolvePivotDefinition($module, $model1, $model2, $options);

        $this->artifactWriter->ensureDoesNotExist($this->modelPath($module, $pivot->modelName), 'Pivot model', $pivot->modelName, $module);

        $modelPath = $this->createPivotModel($module, $pivot, $options);
        $migrationPath = $this->createPivotMigration($module, $pivot, $options);

        return $this->buildResult($pivot, $modelPath, $migrationPath);
    }

    private function resolvePivotDefinition(
        ModuleDefinition $module,
        string $model1,
        ?string $model2,
        PivotGenerationOptions $options,
    ): PivotDefinition {
        [$first, $second] = $this->entitiesResolver->execute($module, $model1, $model2);

        return new PivotDefinition(
            modelName: $first.$second,
            tableName: $options->table ?? $this->defaultTableName($first, $second),
            column1: $options->foreignKey1 ?? $this->defaultForeignKey($first),
            column2: $options->foreignKey2 ?? $this->defaultForeignKey($second),
            secondEntity: $second,
        );
    }

    private function createPivotModel(
        ModuleDefinition $module,
        PivotDefinition $pivot,
        PivotGenerationOptions $options,
    ): string {
        $path = $this->modelPath($module, $pivot->modelName);
        $this->files->ensureDirectoryExists(dirname($path));

        $content = $this->buildModelContent($module, $pivot, $options);

        $this->files->put($path, $content);

        return $path;
    }

    private function createPivotMigration(
        ModuleDefinition $module,
        PivotDefinition $pivot,
        PivotGenerationOptions $options,
    ): ?string {
        if (! $options->withMigration) {
            return null;
        }

        $migrationOptions = new MigrationGenerationOptions(
            table: $pivot->tableName,
            pivot: true,
            morph: $options->morph,
            foreignKey1: $pivot->column1,
            foreignKey2: $pivot->column2,
        );

        $result = $this->migrationCreator->execute(
            $module,
            "create_{$pivot->tableName}_table",
            $migrationOptions,
        );

        return $result->path;
    }

    private function buildResult(
        PivotDefinition $pivot,
        string $modelPath,
        ?string $migrationPath,
    ): PivotGenerationResult {
        return new PivotGenerationResult(
            modelPath: $modelPath,
            migrationPath: $migrationPath,
            modelName: $pivot->modelName,
            tableName: $pivot->tableName,
        );
    }

    private function defaultTableName(string $first, string $second): string
    {
        return Str::snake($first).'_'.Str::snake($second);
    }

    private function defaultForeignKey(string $entity): string
    {
        return Str::snake($entity).'_id';
    }

    private function buildModelContent(
        ModuleDefinition $module,
        PivotDefinition $pivot,
        PivotGenerationOptions $options,
    ): string {
        $stubFile = $options->morph ? 'Pivot.morph.php.stub' : 'Pivot.php.stub';
        $stub = $this->files->get($this->stubRenderer->path($stubFile));

        return str_replace(
            ['{{ namespace }}', '{{ class }}', '{{ table }}'],
            [$module->namespace.'\\Models', $pivot->modelName, $pivot->tableName],
            $stub
        );
    }

    private function modelPath(ModuleDefinition $module, string $modelName): string
    {
        return $module->path.DIRECTORY_SEPARATOR.'Models'.DIRECTORY_SEPARATOR."{$modelName}.php";
    }
}
