<?php

namespace Studeo\Modular\Console;

use Studeo\Modular\Console\Actions\DeleteLivewireComponentAction;
use Studeo\Modular\Console\Actions\ResolveLivewireFormAction;
use Studeo\Modular\Console\Prompts\LivewireComponentInputResolver;
use Studeo\Modular\Console\Prompts\ModulePicker;
use Studeo\Modular\DataObjects\LivewireComponentDefinition;
use Studeo\Modular\DataObjects\LivewireDeletionResult;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Exceptions\NoTerminalException;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\warning;

class ModuleLivewireDeleteCommand extends ModuleCommand
{
    protected $signature = 'module:livewire:delete
        {name? : The name of the Livewire component to delete}
        {--module= : The name of the module}
        {--force : Force deletion without confirmation}';

    protected $aliases = ['module:livewire:rm'];

    protected $description = 'Delete a Livewire component from a module';

    public function handle(
        LivewireComponentInputResolver $resolver,
        DeleteLivewireComponentAction $deleter,
        ModulePicker $picker,
    ): int {
        try {
            $module = $picker->resolve($this, $this->scalarOption('module'));
            $component = $resolver->resolve($this, $module, $this->stringArgument('name'), $this->forcedExample($module), promptVerb: 'delete');

            return match (true) {
                $component === null => self::SUCCESS,
                ! $this->confirmDeletion($module, $component) => $this->cancelDeletion($component),
                default => $this->deleteComponent($deleter, $module, $component),
            };
        } catch (Throwable $exception) {
            return $this->reportFailure($exception);
        }
    }

    private function deleteComponent(
        DeleteLivewireComponentAction $deleter,
        ModuleDefinition $module,
        LivewireComponentDefinition $component,
    ): int {
        $result = $deleter->execute($module, $component->name);
        $this->renderDeletionReport($result, $module);

        return self::SUCCESS;
    }

    private function cancelDeletion(LivewireComponentDefinition $component): int
    {
        $this->info("Component [{$component->name}] was not deleted.");

        return self::SUCCESS;
    }

    private function confirmDeletion(ModuleDefinition $module, LivewireComponentDefinition $component): bool
    {
        if ($this->option('force')) {
            return true;
        }

        if (! $this->canPrompt()) {
            throw new NoTerminalException('the --force option', $this->forcedExample($module));
        }

        warning("This will permanently delete Livewire component [{$component->name}] and all associated files.");
        $this->line('<comment>Files to be deleted:</comment>');
        foreach ($component->files as $file) {
            $this->line("  - {$file}");
        }

        $this->listForm($module, $component);

        return confirm(
            label: "Are you sure you want to delete component [{$component->name}]?",
            default: false,
        );
    }

    private function forcedExample(ModuleDefinition $module): string
    {
        return "{$this->getName()} Counter --module={$module->name} --force";
    }

    /**
     * The Form goes with its component unless another file uses it, so say which.
     */
    private function listForm(ModuleDefinition $module, LivewireComponentDefinition $component): void
    {
        $form = app(ResolveLivewireFormAction::class)->execute($module, $component);

        if ($form === null) {
            return;
        }

        $this->line($form->shared()
            ? "  - {$form->path} (kept: other files use it)"
            : "  - {$form->path}");
    }

    private function renderDeletionReport(LivewireDeletionResult $result, ModuleDefinition $module): void
    {
        $this->info("Livewire component [{$result->component->name}] deleted successfully from module [{$module->name}].");
        $this->line('<comment>Deleted files:</comment>');
        foreach ($result->deletedFiles as $file) {
            $this->line("  - {$file}");
        }

        foreach ($result->notices as $notice) {
            $this->warn($notice);
        }
    }
}
