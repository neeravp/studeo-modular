<?php

use Illuminate\Support\Str;
use Studeo\Modular\DataObjects\ModuleDefinition;

/**
 * A throwaway module on disk: the given models (name => table, or a bare name for the
 * conventional table) and, for each name in $factories, a factory file.
 *
 * @param  array<int|string, string>  $models
 * @param  array<int, string>  $factories
 */
function moduleWithModels(array $models, array $factories = []): ModuleDefinition
{
    $path = sys_get_temp_dir().'/modular-models-'.bin2hex(random_bytes(4));
    mkdir($path.'/Models', 0777, true);
    mkdir($path.'/database/factories', 0777, true);

    foreach ($models as $key => $value) {
        $name = is_int($key) ? $value : $key;
        $table = is_int($key) ? Str::snake(Str::pluralStudly($name)) : $value;
        file_put_contents("{$path}/Models/{$name}.php", "<?php\n\nnamespace Studeo\\Blog\\Models;\n\nclass {$name}\n{\n    protected \$table = '{$table}';\n}\n");
    }

    foreach ($factories as $name) {
        file_put_contents("{$path}/database/factories/{$name}Factory.php", "<?php\n\nclass {$name}Factory {}\n");
    }

    register_shutdown_function(function () use ($path): void {
        foreach (glob("{$path}/*/*") ?: [] as $file) {
            is_dir($file) ? null : unlink($file);
        }
        array_map('unlink', glob("{$path}/database/factories/*") ?: []);
        array_map('rmdir', glob("{$path}/database/*") ?: []);
        array_map('rmdir', glob("{$path}/*") ?: []);
        rmdir($path);
    });

    return new ModuleDefinition('Blog', 'Studeo\\Blog', $path);
}
