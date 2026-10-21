<?php

namespace Studeo\Modular\Console\Actions;

use RuntimeException;
use Studeo\Modular\Console\Generation\ModuleGuard;
use Studeo\Modular\Console\Generation\NameSanitizer;
use Studeo\Modular\DataObjects\LivewireComponentDefinition;
use Studeo\Modular\DataObjects\LivewireComponentForm;
use Studeo\Modular\DataObjects\LivewireFormMove;
use Studeo\Modular\DataObjects\LivewireMoveResult;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Support\LivewirePaths;

final class MoveLivewireComponentAction
{
    public function __construct(
        private readonly DiscoverLivewireComponentsAction $discoverer,
        private readonly MoveLivewireClassicComponentAction $classicMover,
        private readonly MoveLivewireMfcComponentAction $mfcMover,
        private readonly MoveLivewireSfcComponentAction $sfcMover,
        private readonly ResolveLivewireFormAction $forms,
        private readonly MoveLivewireFormAction $formMover,
        private readonly NameSanitizer $sanitizer,
        private readonly ModuleGuard $moduleGuard,
        private readonly LivewirePaths $livewirePaths,
    ) {}

    public function execute(
        ModuleDefinition $sourceModule,
        string $sourceName,
        ModuleDefinition $targetModule,
        string $targetName,
    ): LivewireMoveResult {
        $this->ensureModulesExist($sourceModule, $targetModule);

        $cleanSource = $this->sanitizer->classPath($sourceName, 'component');
        $cleanTarget = $this->sanitizer->classPath($targetName, 'component');

        $this->ensureMoveIsNotNoop($sourceModule, $cleanSource, $targetModule, $cleanTarget);

        $from = $this->ensureSourceComponentExists($sourceModule, $cleanSource);

        $this->ensureTargetComponentDoesNotExist($targetModule, $cleanTarget);

        return $this->moveComponentWithItsForm($sourceModule, $cleanSource, $targetModule, $cleanTarget, $from);
    }

    private function moveComponentWithItsForm(
        ModuleDefinition $sourceModule,
        string $cleanSource,
        ModuleDefinition $targetModule,
        string $cleanTarget,
        LivewireComponentDefinition $from,
    ): LivewireMoveResult {
        $form = $this->forms->execute($sourceModule, $from);
        $formMove = $this->planFormMove($sourceModule, $form, $targetModule, $cleanTarget);

        $movedFiles = $this->moveComponentFiles($sourceModule, $cleanSource, $targetModule, $cleanTarget, $from);

        $to = $this->resolveTargetDefinition($targetModule, $cleanTarget, $from->type, $movedFiles);
        $formFiles = $this->moveFormFiles($formMove, $to->path);

        return new LivewireMoveResult($from, $to, [...$movedFiles, ...$formFiles], $this->formNotices($form));
    }

    private function ensureModulesExist(ModuleDefinition $sourceModule, ModuleDefinition $targetModule): void
    {
        $this->moduleGuard->ensureExists($sourceModule);
        $this->moduleGuard->ensureExists($targetModule);
    }

    private function planFormMove(
        ModuleDefinition $sourceModule,
        ?LivewireComponentForm $form,
        ModuleDefinition $targetModule,
        string $cleanTarget,
    ): ?LivewireFormMove {
        if ($this->staysInPlace($form)) {
            return null;
        }

        return $this->formMover->plan($sourceModule, $form, $targetModule, $cleanTarget);
    }

    /**
     * @phpstan-assert-if-false LivewireComponentForm $form
     */
    private function staysInPlace(?LivewireComponentForm $form): bool
    {
        return $form === null || $form->shared();
    }

    /**
     * @return array<string, string>
     */
    private function moveComponentFiles(
        ModuleDefinition $sourceModule,
        string $cleanSource,
        ModuleDefinition $targetModule,
        string $cleanTarget,
        LivewireComponentDefinition $from,
    ): array {
        return match ($from->type) {
            'classic' => $this->classicMover->execute($sourceModule, $cleanSource, $targetModule, $cleanTarget, $from),
            'mfc' => $this->mfcMover->execute($sourceModule, $cleanSource, $targetModule, $cleanTarget, $from),
            'sfc' => $this->sfcMover->execute($sourceModule, $cleanSource, $targetModule, $cleanTarget, $from),
            default => throw new RuntimeException("Unsupported Livewire component type [{$from->type}]."),
        };
    }

    /**
     * @return array<string, string>
     */
    private function moveFormFiles(?LivewireFormMove $formMove, string $componentPath): array
    {
        if ($formMove === null) {
            return [];
        }

        return $this->formMover->execute($formMove, $componentPath);
    }

    /**
     * @return array<int, string>
     */
    private function formNotices(?LivewireComponentForm $form): array
    {
        return $form?->shared()
            ? ['Left Form ['.$form->path.'] in place because other files use it: '.implode(', ', $form->references).'. The component still uses it.']
            : [];
    }

    private function ensureMoveIsNotNoop(
        ModuleDefinition $sourceModule,
        string $cleanSource,
        ModuleDefinition $targetModule,
        string $cleanTarget,
    ): void {
        if ($this->isSameComponent($sourceModule, $cleanSource, $targetModule, $cleanTarget)) {
            throw new RuntimeException("Cannot move component [{$cleanSource}] to the same name in module [{$sourceModule->name}].");
        }
    }

    private function isSameComponent(
        ModuleDefinition $sourceModule,
        string $cleanSource,
        ModuleDefinition $targetModule,
        string $cleanTarget,
    ): bool {
        return $sourceModule->name === $targetModule->name && $cleanSource === $cleanTarget;
    }

    private function ensureSourceComponentExists(ModuleDefinition $module, string $name): LivewireComponentDefinition
    {
        $component = $this->discoverer->find($module, $name);

        if ($component === null) {
            throw new RuntimeException("Livewire component [{$name}] does not exist in module [{$module->name}].");
        }

        return $component;
    }

    private function ensureTargetComponentDoesNotExist(ModuleDefinition $module, string $name): void
    {
        $existing = $this->discoverer->find($module, $name);

        if ($existing !== null) {
            throw new RuntimeException("Livewire component [{$name}] already exists in module [{$module->name}].");
        }
    }

    /**
     * @param  array<string, string>  $movedFiles
     */
    private function resolveTargetDefinition(
        ModuleDefinition $targetModule,
        string $cleanTarget,
        string $type,
        array $movedFiles,
    ): LivewireComponentDefinition {
        return $this->discoverer->resolveComponentDefinition(
            module: $targetModule,
            name: $cleanTarget,
            target: $type === 'classic'
                ? $this->livewirePaths->buildClassicFqcn($targetModule, $cleanTarget)
                : $this->resolveTargetComponentPath($type, $targetModule, $cleanTarget),
            path: $this->resolveTargetComponentPath($type, $targetModule, $cleanTarget),
            type: $type,
            files: array_values($movedFiles),
        );
    }

    private function resolveTargetComponentPath(string $type, ModuleDefinition $module, string $name): string
    {
        return match ($type) {
            'mfc' => $this->livewirePaths->resolveMfcDirectory($module, $name),
            'sfc' => $this->livewirePaths->resolveSfcPath($module, $name),
            default => $this->livewirePaths->resolveClassicClassPath($module, $name),
        };
    }
}
