<?php

use Illuminate\Filesystem\Filesystem;
use Studeo\Modular\Tests\Helpers\OrderedFilesystem;

/**
 * Binds an OrderedFilesystem as the container's Filesystem, so every collaborator resolved after
 * this call logs its calls. Resolve the Action under test after calling it.
 */
function orderedFilesystem(): OrderedFilesystem
{
    $files = new OrderedFilesystem;
    $files->modulesRoot = config('modular.root_path');
    app()->instance(Filesystem::class, $files);

    return $files;
}

/**
 * The calls a fixture pins, one per line, with the modules root as <modules> and the package as
 * <package>.
 *
 * @return array<int, string>
 */
function callFixture(string $name, string $directory = 'livewire-calls'): array
{
    return explode("\n", rtrim((string) file_get_contents(__DIR__."/../fixtures/{$directory}/{$name}.txt"), "\n"));
}

/**
 * Paths relative to the module, so expectations carry no machine paths.
 *
 * @param  array<int, string>  $paths
 * @return array<int, string>
 */
function relativeTo(string $modulePath, array $paths): array
{
    return array_map(fn (string $path): string => str_replace("{$modulePath}/", '', $path), $paths);
}
