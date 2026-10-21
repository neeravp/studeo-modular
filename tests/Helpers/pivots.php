<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreatePivotAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\PivotGenerationOptions;

/**
 * The model name, table and the two unique-key columns in the order the migration lists them, so
 * a test sees which entity came first and where a compound name was split.
 *
 * @param  array<int, string>  $models
 * @return array{string, string, string, string}
 */
function pivotResolution(ModuleDefinition $module, array $models, string $first, ?string $second = null, ?PivotGenerationOptions $options = null): array
{
    seedPivotModels($module, $models);

    $result = app(CreatePivotAction::class)->execute($module, $first, $second, $options);
    preg_match("/unique\(\['(.*)', '(.*)'\]\)/", File::get($result->migrationPath), $columns);

    return [$result->modelName, $result->tableName, $columns[1], $columns[2]];
}

/**
 * @param  array<int, string>  $models
 */
function seedPivotModels(ModuleDefinition $module, array $models): void
{
    foreach ($models as $model) {
        File::put("{$module->path}/Models/{$model}.php", "<?php\n");
    }
}

/**
 * @return array<int, string>
 */
function pivotModuleFiles(ModuleDefinition $module): array
{
    $paths = array_map(fn ($file): string => $file->getPathname(), File::allFiles($module->path));
    sort($paths);

    return $paths;
}
