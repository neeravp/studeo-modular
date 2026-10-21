<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use Studeo\Modular\Console\Generation\LivewireMoveRewriter;
use Studeo\Modular\DataObjects\LivewireComponentDefinition;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Support\LivewirePaths;

final class MoveLivewireSfcComponentAction
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
            ...$this->moveBladeFile($sourceModule, $sourceName, $targetModule, $targetName, $from),
            ...$this->moveRewriter->moveFeatureTestIfExists($sourceModule, $sourceName, $targetModule, $targetName),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function moveBladeFile(
        ModuleDefinition $sourceModule,
        string $sourceName,
        ModuleDefinition $targetModule,
        string $targetName,
        LivewireComponentDefinition $from,
    ): array {
        $targetSfcPath = $this->livewirePaths->resolveSfcPath($targetModule, $targetName);
        $sfcContent = $this->files->get($from->path);
        $rewrittenSfc = $this->rewriteSfcContent(
            $sfcContent,
            $sourceModule,
            $sourceName,
            $targetModule,
            $targetName
        );

        return $this->moveRewriter->replaceAndRelocateFile(
            $from->path,
            $targetSfcPath,
            $rewrittenSfc,
            $this->livewirePaths->classesLivewirePath($sourceModule)
        );
    }

    private function rewriteSfcContent(
        string $content,
        ModuleDefinition $sourceModule,
        string $sourceName,
        ModuleDefinition $targetModule,
        string $targetName,
    ): string {
        $rewritten = $this->moveRewriter->rewriteBladeTemplateContent(
            $content,
            $sourceName,
            $targetName,
            'Livewire 4 Component'
        );

        return $this->moveRewriter->rewriteLayoutAttribute($rewritten, $sourceModule, $targetModule);
    }
}
