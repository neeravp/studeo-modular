<?php

namespace Studeo\Modular\Console;

use RuntimeException;
use Studeo\Modular\Console\Actions\DiscoverInstalledModulesAction;
use Studeo\Modular\Console\Actions\DiscoverModulesAction;
use Studeo\Modular\Console\Actions\MoveVueComponentAction;
use Studeo\Modular\Console\Actions\ResolveModuleAction;
use Studeo\Modular\Console\Prompts\ModulePicker;
use Studeo\Modular\Console\Prompts\VueComponentInputResolver;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\VueComponentDefinition;
use Studeo\Modular\DataObjects\VueMoveContext;
use Studeo\Modular\DataObjects\VueMoveResult;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class ModuleVueMoveCommand extends ModuleCommand
{
    protected $signature = 'module:vue:move
        {name? : The name of the Vue component to move}
        {new-name? : The new name for the Vue component}
        {--module= : The source module containing the component}
        {--to-module= : The target module (defaults to source module)}
        {--p|page : Specify that the target component should be an Inertia page}
        {--force : Force move without confirmation}';

    protected $aliases = ['module:page:move', 'module:vue:mv'];

    protected $description = 'Move or rename a Vue component within or across modules';

    public function handle(
        VueComponentInputResolver $resolver,
        MoveVueComponentAction $mover,
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

    private function resolveMoveContext(VueComponentInputResolver $resolver, ModulePicker $picker): ?VueMoveContext
    {
        $sourceModule = $picker->resolve($this, $this->scalarOption('module'));
        $example = "{$this->getName()} Card Renamed --module={$sourceModule->name} --force";
        $component = $resolver->resolve($this, $sourceModule, $this->stringArgument('name'), $example, promptVerb: 'move', isPage: $this->isPageExplicit());

        if ($component === null) {
            return null;
        }

        $this->ensureGivenWithoutTerminal([
            'the [new-name] argument' => $this->argument('new-name') !== null,
            'the --force option' => $this->isForced(),
        ], $example);

        return new VueMoveContext(
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

    private function resolveTargetComponentName(VueComponentDefinition $component): string
    {
        $newName = $this->stringArgument('new-name');

        return $newName !== null
            ? (string) $newName
            : $this->promptForTargetComponentName($component);
    }

    private function promptForTargetComponentName(VueComponentDefinition $component): string
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

    private function confirmMove(VueMoveContext $context): bool
    {
        return $this->isForced() || confirm(
            label: "Are you sure you want to move [{$context->component->name}] in [{$context->sourceModule->name}] to {$context->targetDescription()}?",
            default: true,
        );
    }

    private function executeMove(MoveVueComponentAction $mover, VueMoveContext $context): int
    {
        $result = $mover->execute(
            sourceModule: $context->sourceModule,
            sourceName: $context->component->name,
            targetModule: $context->targetModule,
            targetName: $context->targetName,
            targetIsPage: $context->component->isPage(),
        );

        $this->renderMoveReport($result);

        return self::SUCCESS;
    }

    private function cancelMove(VueMoveContext $context): int
    {
        $this->info("Component [{$context->component->name}] was not moved.");

        return self::SUCCESS;
    }

    private function isPageExplicit(): ?bool
    {
        return $this->option('page') ? true : null;
    }

    private function isForced(): bool
    {
        return (bool) $this->option('force');
    }

    private function renderMoveReport(VueMoveResult $result): void
    {
        $this->info("{$result->from->displayType()} [{$result->from->name}] moved to [{$result->to->name}] in module [{$result->to->module->name}].");
        $this->line("<comment>Moved file:</comment> {$result->fromPath} => {$result->toPath}");
    }
}
