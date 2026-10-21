<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use Studeo\Modular\Console\Generation\DirectoryPruner;
use Studeo\Modular\Console\Generation\LivewireMoveRewriter;
use Studeo\Modular\DataObjects\LivewireComponentDefinition;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Support\LivewirePaths;

final class MoveLivewireMfcComponentAction
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly DirectoryPruner $directoryPruner,
        private readonly LivewirePaths $livewirePaths,
        private readonly LivewireMoveRewriter $moveRewriter,
    ) {}

    /**
     * Moves the ⚡{name} directory and renames every member file that starts with the old name,
     * so {old}.php, {old}.blade.php, {old}.test.php and the assets become {new}.*.
     *
     * @return array<string, string>
     */
    public function execute(
        ModuleDefinition $sourceModule,
        string $sourceName,
        ModuleDefinition $targetModule,
        string $targetName,
        LivewireComponentDefinition $from,
    ): array {
        $targetDir = $this->livewirePaths->resolveMfcDirectory($targetModule, $targetName);
        $oldBase = str_replace('⚡', '', basename($from->path));
        $newBase = $this->livewirePaths->mfcFileBase($targetName);
        $this->files->ensureDirectoryExists($targetDir);

        $movedFiles = [];

        foreach ($this->files->files($from->path) as $file) {
            $content = $this->rewriteMemberContent($file->getFilename(), $oldBase, $this->files->get($file->getRealPath()), $sourceModule, $sourceName, $targetModule, $targetName);
            $movedFiles[$file->getRealPath()] = $this->writeRenamedMember($targetDir, $file->getFilename(), $oldBase, $newBase, $content);
        }

        $this->removeSourceDirectory($sourceModule, $from->path);

        return $movedFiles;
    }

    private function writeRenamedMember(string $targetDir, string $filename, string $oldBase, string $newBase, string $content): string
    {
        $path = $targetDir.DIRECTORY_SEPARATOR.$this->renamedMember($filename, $oldBase, $newBase);

        $this->files->put($path, $content);

        return $path;
    }

    private function renamedMember(string $filename, string $oldBase, string $newBase): string
    {
        return str_starts_with($filename, $oldBase) ? $newBase.substr($filename, strlen($oldBase)) : $filename;
    }

    private function removeSourceDirectory(ModuleDefinition $sourceModule, string $sourceDir): void
    {
        $this->files->deleteDirectory($sourceDir);
        $this->directoryPruner->pruneEmptyParentDirectories($sourceDir, $this->livewirePaths->classesLivewirePath($sourceModule));
    }

    private function rewriteMemberContent(
        string $filename,
        string $oldBase,
        string $content,
        ModuleDefinition $sourceModule,
        string $sourceName,
        ModuleDefinition $targetModule,
        string $targetName,
    ): string {
        return match (true) {
            $filename === "{$oldBase}.php" => $this->moveRewriter->rewriteLayoutAttribute($content, $sourceModule, $targetModule),
            $filename === "{$oldBase}.test.php" => $this->moveRewriter->rewriteTestContent($content, $this->livewirePaths->livewireAlias($sourceModule, $sourceName), $this->livewirePaths->livewireAlias($targetModule, $targetName)),
            default => $content,
        };
    }
}
