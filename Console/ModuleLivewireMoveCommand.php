<?php

namespace Studeo\Modular\Console;

use RuntimeException;
use Studeo\Modular\Console\Actions\DiscoverInstalledModulesAction;
use Studeo\Modular\Console\Actions\DiscoverModulesAction;
use Studeo\Modular\Console\Actions\MoveLivewireComponentAction;
use Studeo\Modular\Console\Actions\ResolveModuleAction;
use Studeo\Modular\Console\Prompts\LivewireComponentInputResolver;
use Studeo\Modular\Console\Prompts\ModulePicker;
use Studeo\Modular\DataObjects\LivewireComponentDefinition;
use Studeo\Modular\DataObjects\LivewireMoveContext;
use Studeo\Modular\DataObjects\LivewireMoveResult;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class ModuleLivewireMoveCommand extends ModuleCommand
{
    protected $signature = 'module:livewire:move
        {name? : The name of the Livewire component to move}
        {new-name? : The new name for the Livewire component}
        {--module= : The source module containing the component}
        {--to-module= : The target module (defaults to source module)}
        {--force : Force move without confirmation}';

    protected $aliases = ['module:livewire:mv'];

    protected $description = 'Move or rename a Livewire component within or across modules';

    public function handle(
        LivewireComponentInputResolver $resolver,
        MoveLivewireComponentAction $mover,
        ModulePicker $picker,
    ): int {
        try {
            $context = $this->resolveMoveContext($resolver, $picker);

            return match (true) {
                $context === null => self::SUCCESS,
                ! $this->confirmMove($context) => $this->cancelMove($context),
                default => $this->executeMove($mover, $context),
            };
        } catch (Throwable $exception) {
            return $this->reportFailure($exception);
        }
    }

    private function resolveMoveContext(LivewireComponentInputResolver $resolver, ModulePicker $picker): ?LivewireMoveContext
    {
        $sourceModule = $picker->resolve($this, $this->scalarOption('module'));
        $example = "{$this->getName()} Counter Renamed --module={$sourceModule->name} --force";
        $component = $resolver->resolve($this, $sourceModule, $this->stringArgument('name'), $example, promptVerb: 'move');

        if ($component === null) {
            return null;
        }

        $this->ensureGivenWithoutTerminal([
            'the [new-name] argument' => $this->argument('new-name') !== null,
            'the --force option' => $this->isForced(),
        ], $example);

        return new LivewireMoveContext(
            sourceModule: $sourceModule,
            component: $component,
            targetModule: $this->resolveTargetModule($sourceModule),
            targetName: $this->resolveTargetComponentName($component),
        );
    }

    private function resolveTargetModule(ModuleDefinition $sourceModule): ModuleDefinition
    {
        return match (true) {
            $this->hasExplicitTargetModule() => $this->resolveExplicitTargetModule(),
            $this->isForced() => $sourceModule,
            default => $this->promptForTargetModule($sourceModule),
        };
    }

    private function promptForTargetModule(ModuleDefinition $sourceModule): ModuleDefinition
    {
        if ($this->shouldKeepInSameModule($sourceModule)) {
            return $sourceModule;
        }

        $candidates = $this->findOtherAvailableModules($sourceModule);

        if (empty($candidates)) {
            throw new RuntimeException('No other modules found to move component to.');
        }

        return $this->promptAndResolveTargetModule($candidates);
    }

    private function resolveTargetComponentName(LivewireComponentDefinition $component): string
    {
        $newName = $this->stringArgument('new-name');

        return $newName !== null
            ? (string) $newName
            : $this->promptForTargetComponentName($component);
    }

    private function promptForTargetComponentName(LivewireComponentDefinition $component): string
    {
        return text(
            label: 'What should the new component name be?',
            default: $component->name,
            required: true,
        );
    }

    private function hasExplicitTargetModule(): bool
    {
        return $this->option('to-module') !== null;
    }

    private function resolveExplicitTargetModule(): ModuleDefinition
    {
        return app(ResolveModuleAction::class)->execute((string) $this->stringOption('to-module'));
    }

    private function shouldKeepInSameModule(ModuleDefinition $sourceModule): bool
    {
        return confirm(
            label: "Keep component in module [{$sourceModule->name}]?",
            default: true,
        );
    }

    /**
     * @param  array<string>  $candidates
     */
    private function promptAndResolveTargetModule(array $candidates): ModuleDefinition
    {
        $selectedModuleName = select(
            label: 'Which module should the component be moved to?',
            options: $candidates,
        );

        return app(ResolveModuleAction::class)->execute((string) $selectedModuleName);
    }

    /**
     * @return array<string>
     */
    private function findOtherAvailableModules(ModuleDefinition $sourceModule): array
    {
        return array_values(array_filter(
            $this->resolveAvailableModuleNames(),
            fn (string $name): bool => strcasecmp($name, $sourceModule->name) !== 0
        ));
    }

    /**
     * @return array<string>
     */
    private function resolveAvailableModuleNames(): array
    {
        $installed = app(DiscoverInstalledModulesAction::class)->execute();
        $discovered = app(DiscoverModulesAction::class)->execute();

        return array_unique(array_merge(
            array_keys($installed),
            array_keys($discovered),
        ));
    }

    private function confirmMove(LivewireMoveContext $context): bool
    {
        return $this->isForced() || confirm(
            label: "Are you sure you want to move [{$context->component->name}] in [{$context->sourceModule->name}] to {$context->targetDescription()}?",
            default: true,
        );
    }

    private function executeMove(MoveLivewireComponentAction $mover, LivewireMoveContext $context): int
    {
        $result = $mover->execute(
            sourceModule: $context->sourceModule,
            sourceName: $context->component->name,
            targetModule: $context->targetModule,
            targetName: $context->targetName,
        );

        $this->renderMoveReport($result);

        return self::SUCCESS;
    }

    private function cancelMove(LivewireMoveContext $context): int
    {
        $this->info("Component [{$context->component->name}] was not moved.");

        return self::SUCCESS;
    }

    private function isForced(): bool
    {
        return (bool) $this->option('force');
    }

    private function renderMoveReport(LivewireMoveResult $result): void
    {
        $this->info("Livewire component [{$result->from->name}] moved to [{$result->to->name}] in module [{$result->to->module->name}].");
        $this->line('<comment>Moved files:</comment>');
        foreach ($result->movedFiles as $fromPath => $toPath) {
            $this->line("  - {$fromPath} => {$toPath}");
        }

        foreach ($result->notices as $notice) {
            $this->warn($notice);
        }
    }
}
