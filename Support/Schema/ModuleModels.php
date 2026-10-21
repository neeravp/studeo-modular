<?php

namespace Studeo\Modular\Support\Schema;

use Illuminate\Support\Str;
use RuntimeException;
use Studeo\Modular\DataObjects\ModuleDefinition;

/**
 * What a module's Models/ and database/factories hold on disk: the model names, the table each
 * one declares (the conventional table when it declares none), and whether a model has a factory.
 */
final class ModuleModels
{
    /**
     * @return array<int, string>
     */
    public static function names(ModuleDefinition $module): array
    {
        $files = glob(rtrim($module->path, '/').'/Models/*.php') ?: [];
        sort($files);

        return array_map(fn (string $file): string => basename($file, '.php'), $files);
    }

    /**
     * @return array<string, string> table => model name
     */
    public static function tables(ModuleDefinition $module): array
    {
        $tables = [];

        foreach (self::names($module) as $name) {
            $declared = preg_match('/\$table\s*=\s*\'([^\']+)\'/', (string) file_get_contents(rtrim($module->path, '/')."/Models/{$name}.php"), $match) === 1;
            $tables[$declared ? $match[1] : SchemaFiles::tableFor($name)] ??= $name;
        }

        return $tables;
    }

    /**
     * The model a --model option names: a short or studly name of a model in the module's Models/.
     * A class name, or a name the module has no model for, is refused with the module's models.
     *
     * @throws RuntimeException
     */
    public static function resolve(ModuleDefinition $module, string $name): string
    {
        $models = self::names($module);
        $known = $models === [] ? 'The module has no models yet.' : "The module's models: ".implode(', ', $models).'.';
        $clean = trim($name);

        if (self::isClassName($clean)) {
            throw new RuntimeException("Model [{$clean}] is a class name, and a model must be one of module [{$module->name}]: pass its name, e.g. ".($models[0] ?? 'Post').". {$known}");
        }

        if (! in_array(Str::studly($clean), $models, true)) {
            throw new RuntimeException("Model [{$clean}] is not a model of module [{$module->name}]. {$known}");
        }

        return Str::studly($clean);
    }

    public static function factoryFile(string $model): string
    {
        return "database/factories/{$model}Factory.php";
    }

    public static function hasFactory(ModuleDefinition $module, string $model): bool
    {
        return is_file(rtrim($module->path, '/').'/'.self::factoryFile($model));
    }

    private static function isClassName(string $name): bool
    {
        return str_contains($name, '\\') || str_contains($name, '/');
    }
}
