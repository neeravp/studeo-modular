<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use Studeo\Modular\Console\Generation\LivewireMoveRewriter;
use Studeo\Modular\DataObjects\LivewireComponentDefinition;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\ModuleManifest;
use Studeo\Modular\Support\LivewirePaths;

final class MoveLivewireClassicComponentAction
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly LivewirePaths $livewirePaths,
        private readonly LivewireMoveRewriter $moveRewriter,
    ) {}

    /**
     * @return array<string, string>
     */
    public function execute(
        ModuleDefinition $sourceModule,
        string $sourceName,
        ModuleDefinition $targetModule,
        string $targetName,
        LivewireComponentDefinition $from,
    ): array {
        return [
            ...$this->moveClassFile($sourceModule, $sourceName, $targetModule, $targetName, $from),
            ...$this->moveViewFileIfExists($sourceModule, $sourceName, $targetModule, $targetName),
            ...$this->moveRewriter->moveFeatureTestIfExists($sourceModule, $sourceName, $targetModule, $targetName),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function moveClassFile(
        ModuleDefinition $sourceModule,
        string $sourceName,
        ModuleDefinition $targetModule,
        string $targetName,
        LivewireComponentDefinition $from,
    ): array {
        $targetClassPath = $this->livewirePaths->resolveClassicClassPath($targetModule, $targetName);
        $classContent = $this->files->get($from->path);
        $rewrittenClass = $this->rewriteClassContent(
            $classContent,
            $sourceModule,
            $sourceName,
            $targetModule,
            $targetName
        );

        return $this->moveRewriter->replaceAndRelocateFile(
            $from->path,
            $targetClassPath,
            $rewrittenClass,
            $this->livewirePaths->classesLivewirePath($sourceModule)
        );
    }

    /**
     * @return array<string, string>
     */
    private function moveViewFileIfExists(
        ModuleDefinition $sourceModule,
        string $sourceName,
        ModuleDefinition $targetModule,
        string $targetName,
    ): array {
        $sourceViewPath = $this->livewirePaths->resolveClassicViewPath($sourceModule, $sourceName);

        if (! $this->files->isFile($sourceViewPath)) {
            return [];
        }

        $targetViewPath = $this->livewirePaths->resolveClassicViewPath($targetModule, $targetName);
        $viewContent = $this->files->get($sourceViewPath);
        $rewrittenView = $this->moveRewriter->rewriteBladeTemplateContent($viewContent, $sourceName, $targetName);

        return $this->moveRewriter->replaceAndRelocateFile(
            $sourceViewPath,
            $targetViewPath,
            $rewrittenView,
            $this->livewirePaths->viewsLivewirePath($sourceModule)
        );
    }

    private function rewriteClassContent(
        string $content,
        ModuleDefinition $sourceModule,
        string $sourceName,
        ModuleDefinition $targetModule,
        string $targetName,
    ): string {
        $content = $this->rewriteNamespace($content, $sourceModule, $sourceName, $targetModule, $targetName);
        $content = $this->rewriteClassName($content, $sourceName, $targetName);
        $content = $this->rewriteViewCall($content, $sourceModule, $sourceName, $targetModule, $targetName);

        return $this->moveRewriter->rewriteLayoutAttribute($content, $sourceModule, $targetModule);
    }

    private function rewriteNamespace(
        string $content,
        ModuleDefinition $sourceModule,
        string $sourceName,
        ModuleDefinition $targetModule,
        string $targetName,
    ): string {
        $oldNamespace = $this->livewirePaths->buildClassicNamespace($sourceModule, $sourceName);
        $newNamespace = $this->livewirePaths->buildClassicNamespace($targetModule, $targetName);

        return str_replace("namespace {$oldNamespace};", "namespace {$newNamespace};", $content);
    }

    private function rewriteClassName(string $content, string $sourceName, string $targetName): string
    {
        $oldClass = basename($sourceName);
        $newClass = basename($targetName);

        return preg_replace(
            "/class\s+{$oldClass}\s+extends\s+Component/",
            "class {$newClass} extends Component",
            $content
        );
    }

    private function rewriteViewCall(
        string $content,
        ModuleDefinition $sourceModule,
        string $sourceName,
        ModuleDefinition $targetModule,
        string $targetName,
    ): string {
        $oldView = ModuleManifest::keyFor($sourceModule->name).'::livewire.'.$this->livewirePaths->formatKebabPath($sourceName);
        $newView = ModuleManifest::keyFor($targetModule->name).'::livewire.'.$this->livewirePaths->formatKebabPath($targetName);

        return str_replace("view('{$oldView}')", "view('{$newView}')", $content);
    }
}
