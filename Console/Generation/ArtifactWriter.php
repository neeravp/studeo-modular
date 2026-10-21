<?php

namespace Studeo\Modular\Console\Generation;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Studeo\Modular\DataObjects\ModuleDefinition;

final class ArtifactWriter
{
    public function __construct(private readonly Filesystem $files) {}

    /**
     * Enforce that the target artifact file does not already exist on disk.
     */
    public function ensureDoesNotExist(
        string $path,
        string $type,
        string $name,
        ModuleDefinition $module,
    ): void {
        if ($this->files->isFile($path)) {
            $className = basename(str_replace('/', DIRECTORY_SEPARATOR, $name));
            throw new RuntimeException("{$type} [{$className}] already exists in module [{$module->name}].");
        }
    }

    /**
     * Write the artifact, creating its directory first.
     */
    public function write(string $path, string $content): void
    {
        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $content);
    }
}
