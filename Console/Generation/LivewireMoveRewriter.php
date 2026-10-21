<?php

namespace Studeo\Modular\Console\Generation;

use Illuminate\Filesystem\Filesystem;
use Studeo\Modular\Console\Actions\EnsureLivewireLayoutExistsAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\ModuleManifest;
use Studeo\Modular\Support\LivewirePaths;

final class LivewireMoveRewriter
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly EnsureLivewireLayoutExistsAction $layoutEnsurer,
        private readonly DirectoryPruner $directoryPruner,
        private readonly LivewirePaths $livewirePaths,
        private readonly ArtifactWriter $artifactWriter,
    ) {}

    /**
     * Write new content, delete source file, prune empty parents, and return the path mapping.
     *
     * @return array<string, string>
     */
    public function replaceAndRelocateFile(
        string $sourcePath,
        string $targetPath,
        string $content,
        string $pruneBoundary,
    ): array {
        $this->artifactWriter->write($targetPath, $content);
        $this->files->delete($sourcePath);
        $this->directoryPruner->pruneEmptyParentDirectories($sourcePath, $pruneBoundary);

        return [$sourcePath => $targetPath];
    }

    public function rewriteBladeTemplateContent(
        string $content,
        string $sourceName,
        string $targetName,
        string $suffix = 'Livewire Component',
    ): string {
        return str_replace(
            basename($sourceName).' '.$suffix,
            basename($targetName).' '.$suffix,
            $content
        );
    }

    /**
     * @return array<string, string>
     */
    public function moveFeatureTestIfExists(
        ModuleDefinition $sourceModule,
        string $sourceName,
        ModuleDefinition $targetModule,
        string $targetName,
    ): array {
        $sourceTestPath = $this->livewirePaths->resolveExistingFeatureTestPath($sourceModule, $sourceName);

        if ($sourceTestPath === null) {
            return [];
        }

        return $this->relocateFeatureTestFile($sourceTestPath, $sourceModule, $sourceName, $targetModule, $targetName);
    }

    public function rewriteLayoutAttribute(
        string $content,
        ModuleDefinition $sourceModule,
        ModuleDefinition $targetModule,
    ): string {
        if ($sourceModule->name === $targetModule->name) {
            return $content;
        }

        $oldSourceKey = ModuleManifest::keyFor($sourceModule->name);
        $newTargetKey = ModuleManifest::keyFor($targetModule->name);

        $rewritten = str_replace(
            "#[Layout('{$oldSourceKey}::layouts.",
            "#[Layout('{$newTargetKey}::layouts.",
            $content
        );

        if (preg_match("/#\[Layout\('([^']+)'\)/", $rewritten, $matches)) {
            $this->layoutEnsurer->execute($targetModule, $matches[1]);
        }

        return $rewritten;
    }

    /**
     * A generated test addresses its component by alias, so a move rewrites that alias.
     */
    public function rewriteTestContent(string $content, string $oldAlias, string $newAlias): string
    {
        return str_replace("Livewire::test('{$oldAlias}')", "Livewire::test('{$newAlias}')", $content);
    }

    /**
     * @return array<string, string>
     */
    private function relocateFeatureTestFile(
        string $sourceTestPath,
        ModuleDefinition $sourceModule,
        string $sourceName,
        ModuleDefinition $targetModule,
        string $targetName,
    ): array {
        $targetTestPath = $this->livewirePaths->resolveFeatureTestPath($targetModule, $targetName);
        $testContent = $this->files->get($sourceTestPath);
        $rewrittenTest = $this->rewriteTestContent(
            $testContent,
            $this->livewirePaths->livewireAlias($sourceModule, $sourceName),
            $this->livewirePaths->livewireAlias($targetModule, $targetName)
        );

        return $this->replaceAndRelocateFile(
            $sourceTestPath,
            $targetTestPath,
            $rewrittenTest,
            $this->livewirePaths->testsLivewirePath($sourceModule)
        );
    }
}
