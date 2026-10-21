<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Scaffolds the Blog module under a root namespace; the empty string scaffolds it with no prefix,
 * which `module:make` does with a null root namespace, so the manifest says `Blog`.
 */
function blogModule(object $test, string $namespace): void
{
    config(['modular.root_namespace' => $namespace === '' ? null : $namespace]);

    $test->artisan('module:make', ['module' => 'Blog'])->assertSuccessful();
}

/**
 * Runs Pint's default Laravel preset in test mode over copies of the given module files, outside
 * the package so no project configuration applies, and returns the files Pint would change. Pint
 * skips .blade.php, so for a single-file Livewire component the PHP block above `?>` is checked.
 *
 * @param  array<int, string>  $files  paths relative to the module
 * @return array<int, array{path: string, fixers: array<int, string>}>
 */
function pintChanges(object $test, array $files): array
{
    File::ensureDirectoryExists($test->pintPath);

    foreach ($files as $file) {
        $contents = File::get($test->rootModulesPath.'/Blog/'.$file);
        $isBlade = str_ends_with($file, '.blade.php');
        $target = $test->pintPath.'/'.($isBlade ? substr($file, 0, -10).'.php' : $file);

        File::ensureDirectoryExists(dirname($target));
        File::put($target, $isBlade ? rtrim(substr($contents, 0, (int) strpos($contents, '?>')))."\n" : $contents);
    }

    $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/vendor/bin/pint', '--test', '--preset=laravel', '--format=json', $test->pintPath], $test->pintPath);
    $process->run();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['files'] ?? [];
}

/**
 * Every file in a module directory, as paths relative to the module.
 *
 * @return array<int, string>
 */
function moduleFilesIn(object $test, string $directory): array
{
    return array_map(fn ($file): string => $directory.'/'.$file->getFilename(), File::files($test->rootModulesPath.'/Blog/'.$directory));
}

/**
 * The PHP files under a module directory, as paths relative to the module, skipping Blade
 * templates. A directory the module does not have holds none.
 *
 * @return array<int, string>
 */
function modulePhpFiles(object $test, string $directory = ''): array
{
    $base = $test->rootModulesPath.'/Blog/';

    if (! File::isDirectory($base.$directory)) {
        return [];
    }

    $files = array_map(fn ($file): string => substr($file->getPathname(), strlen($base)), File::allFiles($base.$directory));

    return array_values(array_filter($files, fn (string $file): bool => str_ends_with($file, '.php') && ! str_ends_with($file, '.blade.php')));
}
