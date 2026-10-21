<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use Studeo\Modular\Console\Generation\ModuleGuard;
use Studeo\Modular\Console\Generation\StubRenderer;
use Studeo\Modular\DataObjects\MigrationDefinition;
use Studeo\Modular\DataObjects\MigrationGenerationOptions;
use Studeo\Modular\DataObjects\MigrationGenerationResult;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Enums\MigrationType;
use Studeo\Modular\Support\MigrationColumnResolver;

final class CreateMigrationAction
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly ResolveMigrationDefinitionAction $definitions,
        private readonly StubRenderer $stubRenderer,
        private readonly ModuleGuard $moduleGuard,
    ) {}

    public function execute(
        ModuleDefinition $module,
        string $name,
        ?MigrationGenerationOptions $options = null,
    ): MigrationGenerationResult {
        $options ??= new MigrationGenerationOptions;

        $this->moduleGuard->ensureExists($module);

        $definition = $this->definitions->execute($name, $options);

        $path = $this->createMigrationFile($module, $definition, $options);

        return $this->buildResult($definition, $path);
    }

    private function createMigrationFile(
        ModuleDefinition $module,
        MigrationDefinition $definition,
        MigrationGenerationOptions $options,
    ): string {
        $path = $this->migrationPath($module, $definition->name);
        $this->files->ensureDirectoryExists(dirname($path));

        $content = $this->buildContent($definition, $options);

        $this->files->put($path, $content);

        return $path;
    }

    private function buildResult(
        MigrationDefinition $definition,
        string $path,
    ): MigrationGenerationResult {
        return new MigrationGenerationResult(
            path: $path,
            filename: basename($path),
            tableName: $definition->tableName,
            type: $definition->type,
        );
    }

    private function buildContent(MigrationDefinition $definition, MigrationGenerationOptions $options): string
    {
        return match ($definition->type) {
            MigrationType::Create => $this->buildCreateContent($definition, $options),
            MigrationType::Update => $this->buildUpdateContent($definition),
            MigrationType::Blank => $this->buildBlankContent(),
            MigrationType::Pivot => $this->buildPivotContent($definition),
        };
    }

    private function buildCreateContent(MigrationDefinition $definition, MigrationGenerationOptions $options): string
    {
        $columns = $this->resolveCreateColumns($options);
        $columnsContent = $this->formatColumnsContent($columns);

        return $this->populateCreateStub((string) $definition->tableName, $columnsContent);
    }

    /**
     * @return array<int, string>
     */
    private function resolveCreateColumns(MigrationGenerationOptions $options): array
    {
        $columns = [
            ...$this->extractSchemaColumns($options),
            ...$this->extractExplicitColumns($options),
        ];

        return $this->filterExcludedColumns($columns);
    }

    /**
     * @return array<int, string>
     */
    private function extractSchemaColumns(MigrationGenerationOptions $options): array
    {
        return $options->schema === null ? [] : MigrationColumnResolver::columns($options->schema);
    }

    /**
     * @return array<int, string>
     */
    private function extractExplicitColumns(MigrationGenerationOptions $options): array
    {
        return $options->columns;
    }

    /**
     * @param  array<int, string>  $columns
     * @return array<int, string>
     */
    private function filterExcludedColumns(array $columns): array
    {
        return array_values(array_filter(
            $columns,
            fn (string $column): bool => trim($column) !== '$table->id();'
        ));
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function formatColumnsContent(array $columns): string
    {
        if (empty($columns)) {
            return '';
        }

        return implode("\n", array_map(
            fn (string $column): string => "            {$column}",
            $columns
        ))."\n";
    }

    private function populateCreateStub(string $tableName, string $columnsContent): string
    {
        return str_replace(
            ['{{ table }}', '{{ columns }}'],
            [$tableName, $columnsContent],
            $this->createMigrationStub()
        );
    }

    private function createMigrationStub(): string
    {
        return $this->files->get($this->stubRenderer->path('migration.create.php.stub'));
    }

    private function buildUpdateContent(MigrationDefinition $definition): string
    {
        $stub = $this->files->get($this->stubRenderer->path('migration.update.php.stub'));

        return str_replace('{{ table }}', (string) $definition->tableName, $stub);
    }

    private function buildBlankContent(): string
    {
        return $this->files->get($this->stubRenderer->path('migration.blank.php.stub'));
    }

    private function buildPivotContent(MigrationDefinition $definition): string
    {
        return $definition->morphName !== null
            ? $this->buildMorphPivotContent($definition)
            : $this->buildStandardPivotContent($definition);
    }

    private function buildMorphPivotContent(MigrationDefinition $definition): string
    {
        $stub = $this->files->get($this->stubRenderer->path('migration.pivot.morph.php.stub'));

        return str_replace(
            ['{{ table }}', '{{ column1 }}', '{{ morphName }}'],
            [(string) $definition->tableName, (string) $definition->column1, (string) $definition->morphName],
            $stub
        );
    }

    private function buildStandardPivotContent(MigrationDefinition $definition): string
    {
        $stub = $this->files->get($this->stubRenderer->path('migration.pivot.php.stub'));

        return str_replace(
            ['{{ table }}', '{{ column1 }}', '{{ column2 }}'],
            [(string) $definition->tableName, (string) $definition->column1, (string) $definition->column2],
            $stub
        );
    }

    private function migrationPath(ModuleDefinition $module, string $name): string
    {
        $timestamp = date('Y_m_d_His');
        $filename = "{$timestamp}_{$name}.php";

        return $module->path.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations'.DIRECTORY_SEPARATOR.$filename;
    }
}
