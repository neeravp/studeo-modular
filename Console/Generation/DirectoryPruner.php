<?php

namespace Studeo\Modular\Console\Generation;

use Illuminate\Filesystem\Filesystem;

final class DirectoryPruner
{
    public function __construct(private readonly Filesystem $files) {}

    public function pruneEmptyParentDirectories(string $path, string $stopAt): void
    {
        $dir = dirname($path);
        $stopAtRealPath = realpath($stopAt);

        if (! $stopAtRealPath) {
            return;
        }

        $realDir = $this->resolveDirectory($dir);

        while ($this->canPrune($realDir, $stopAtRealPath)) {
            /** @var string $realDir */
            $dir = $this->deleteAndStepUp($realDir);
            $realDir = $this->resolveDirectory($dir);
        }
    }

    private function resolveDirectory(string $dir): string|false
    {
        return $dir ? realpath($dir) : false;
    }

    private function canPrune(string|false $realDir, string $boundaryDir): bool
    {
        return $realDir
            && ! $this->hasReachedBoundaryDirectory($realDir, $boundaryDir)
            && ! $this->directoryIsNotEmpty($realDir);
    }

    private function deleteAndStepUp(string $realDir): string
    {
        $parent = dirname($realDir);
        $this->files->deleteDirectory($realDir);

        return $parent;
    }

    private function hasReachedBoundaryDirectory(string $currentDir, string $boundaryDir): bool
    {
        return $currentDir === $boundaryDir || ! str_starts_with($currentDir, $boundaryDir);
    }

    private function directoryIsNotEmpty(string $dir): bool
    {
        return ! empty($this->files->allFiles($dir)) || ! empty($this->files->directories($dir));
    }
}
