<?php

namespace Studeo\Modular\Support\Schema;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Studeo\Modular\DataObjects\ModuleDefinition;

/**
 * Where a module keeps its schema files: schema/{table}.yaml. The table of a model is derived
 * the way module:model derives it.
 */
final readonly class SchemaFiles
{
    public function __construct(private Filesystem $files = new Filesystem) {}

    public static function tableFor(string $model): string
    {
        return Str::snake(Str::pluralStudly($model));
    }

    public static function path(ModuleDefinition $module, string $table): string
    {
        return rtrim($module->path, '/')."/schema/{$table}.yaml";
    }

    /**
     * The tables that have a schema file in the module, in name order.
     *
     * @return array<int, string>
     */
    public function tables(ModuleDefinition $module): array
    {
        $files = $this->files->glob(rtrim($module->path, '/').'/schema/*.yaml') ?: [];
        sort($files);

        return array_map(fn (string $file): string => basename($file, '.yaml'), $files);
    }
}
