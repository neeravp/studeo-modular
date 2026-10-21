<?php

namespace Studeo\Modular\Tests\Helpers;

use Illuminate\Filesystem\Filesystem;

/**
 * A Filesystem that logs, in order, the calls the code under test makes through its public
 * methods. Calls the Filesystem makes to itself are not logged, so the log shows what was asked,
 * not how Laravel answered it. The modules root and the package directory are shortened.
 */
final class OrderedFilesystem extends Filesystem
{
    /** @var array<int, string> */
    public array $calls = [];

    public string $modulesRoot = '';

    private int $depth = 0;

    public function exists($path)
    {
        return $this->traced('exists', [$path], fn () => parent::exists($path), true);
    }

    public function missing($path)
    {
        return $this->traced('missing', [$path], fn () => parent::missing($path), true);
    }

    public function get($path, $lock = false)
    {
        return $this->traced('get', [$path], fn () => parent::get($path, $lock));
    }

    public function sharedGet($path)
    {
        return $this->traced('sharedGet', [$path], fn () => parent::sharedGet($path));
    }

    public function put($path, $contents, $lock = false)
    {
        return $this->traced('put', [$path], fn () => parent::put($path, $contents, $lock));
    }

    public function replace($path, $content, $mode = null)
    {
        $this->traced('replace', [$path], fn () => parent::replace($path, $content, $mode));
    }

    public function append($path, $data, $lock = false)
    {
        return $this->traced('append', [$path], fn () => parent::append($path, $data, $lock));
    }

    public function prepend($path, $data)
    {
        return $this->traced('prepend', [$path], fn () => parent::prepend($path, $data));
    }

    public function delete($paths)
    {
        return $this->traced('delete', (array) $paths, fn () => parent::delete($paths));
    }

    public function move($path, $target)
    {
        return $this->traced('move', [$path, $target], fn () => parent::move($path, $target));
    }

    public function copy($path, $target)
    {
        return $this->traced('copy', [$path, $target], fn () => parent::copy($path, $target));
    }

    public function isDirectory($directory)
    {
        return $this->traced('isDirectory', [$directory], fn () => parent::isDirectory($directory), true);
    }

    public function isEmptyDirectory($directory, $ignoreDotFiles = false)
    {
        return $this->traced('isEmptyDirectory', [$directory], fn () => parent::isEmptyDirectory($directory, $ignoreDotFiles), true);
    }

    public function isReadable($path)
    {
        return $this->traced('isReadable', [$path], fn () => parent::isReadable($path), true);
    }

    public function isWritable($path)
    {
        return $this->traced('isWritable', [$path], fn () => parent::isWritable($path), true);
    }

    public function isFile($file)
    {
        return $this->traced('isFile', [$file], fn () => parent::isFile($file), true);
    }

    public function glob($pattern, $flags = 0)
    {
        return $this->traced('glob', [$pattern], fn () => parent::glob($pattern, $flags));
    }

    public function files($directory, $hidden = false, array|string|int $depth = 0)
    {
        return $this->traced('files', [$directory], fn () => parent::files($directory, $hidden, $depth));
    }

    public function allFiles($directory, $hidden = false)
    {
        return $this->traced('allFiles', [$directory], fn () => parent::allFiles($directory, $hidden));
    }

    public function directories($directory, array|string|int $depth = 0)
    {
        return $this->traced('directories', [$directory], fn () => parent::directories($directory, $depth));
    }

    public function allDirectories(string $directory): array
    {
        return $this->traced('allDirectories', [$directory], fn () => parent::allDirectories($directory));
    }

    public function ensureDirectoryExists($path, $mode = 0755, $recursive = true)
    {
        $this->traced('ensureDirectoryExists', [$path], fn () => parent::ensureDirectoryExists($path, $mode, $recursive));
    }

    public function makeDirectory($path, $mode = 0755, $recursive = false, $force = false)
    {
        return $this->traced('makeDirectory', [$path], fn () => parent::makeDirectory($path, $mode, $recursive, $force));
    }

    public function moveDirectory($from, $to, $overwrite = false)
    {
        return $this->traced('moveDirectory', [$from, $to], fn () => parent::moveDirectory($from, $to, $overwrite));
    }

    public function copyDirectory($directory, $destination, $options = null)
    {
        return $this->traced('copyDirectory', [$directory, $destination], fn () => parent::copyDirectory($directory, $destination, $options));
    }

    public function deleteDirectory($directory, $preserve = false)
    {
        return $this->traced('deleteDirectory', [$directory], fn () => parent::deleteDirectory($directory, $preserve));
    }

    public function deleteDirectories($directory)
    {
        return $this->traced('deleteDirectories', [$directory], fn () => parent::deleteDirectories($directory));
    }

    public function cleanDirectory($directory)
    {
        return $this->traced('cleanDirectory', [$directory], fn () => parent::cleanDirectory($directory));
    }

    public function lastModified($path)
    {
        return $this->traced('lastModified', [$path], fn () => parent::lastModified($path));
    }

    public function size($path)
    {
        return $this->traced('size', [$path], fn () => parent::size($path));
    }

    public function hash($path, $algorithm = 'md5')
    {
        return $this->traced('hash', [$path], fn () => parent::hash($path, $algorithm));
    }

    /**
     * @param  array<int, mixed>  $paths
     */
    private function traced(string $method, array $paths, callable $call, bool $withResult = false): mixed
    {
        $this->depth++;

        try {
            $result = $call();
        } finally {
            $this->depth--;
        }

        if ($this->depth === 0) {
            $this->calls[] = $this->describe($method, $paths, $withResult ? $result : null, $withResult);
        }

        return $result;
    }

    /**
     * @param  array<int, mixed>  $paths
     */
    private function describe(string $method, array $paths, mixed $result, bool $withResult): string
    {
        $shortened = array_map(fn (mixed $path): string => $this->shorten((string) $path), $paths);

        return trim($method.' '.implode(' ', $shortened).($withResult ? ' '.var_export($result, true) : ''));
    }

    private function shorten(string $path): string
    {
        $roots = array_filter([$this->modulesRoot, realpath($this->modulesRoot) ?: '']);
        $path = str_replace($roots, '<modules>', $path);

        return str_replace(dirname(__DIR__, 2), '<package>', $path);
    }
}
