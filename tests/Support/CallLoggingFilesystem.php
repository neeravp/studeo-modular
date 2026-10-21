<?php

namespace Studeo\Modular\Tests\Support;

use Illuminate\Filesystem\Filesystem;

/**
 * A Filesystem that logs the calls the generators make, in order, with the module and package
 * paths shortened.
 */
final class CallLoggingFilesystem extends Filesystem
{
    /** @var array<int, string> */
    public array $calls = [];

    public string $modulePath = '';

    public function exists($path): bool
    {
        return $this->log('exists', $path, parent::exists($path));
    }

    public function isFile($file): bool
    {
        return $this->log('isFile', $file, parent::isFile($file));
    }

    public function get($path, $lock = false): string
    {
        return $this->log('get', $path, parent::get($path, $lock));
    }

    public function put($path, $contents, $lock = false): int|bool
    {
        return $this->log('put', $path, parent::put($path, $contents, $lock));
    }

    public function ensureDirectoryExists($path, $mode = 0755, $recursive = true): void
    {
        $this->log('ensureDirectoryExists', $path, null);
        parent::ensureDirectoryExists($path, $mode, $recursive);
    }

    private function log(string $method, string $path, mixed $result): mixed
    {
        $short = str_replace([$this->modulePath, dirname(__DIR__, 2).'/Console/stubs'], ['<module>', '<stubs>'], $path);
        $this->calls[] = $method.' '.$short.($method === 'exists' || $method === 'isFile' ? ' '.var_export($result, true) : '');

        return $result;
    }
}
