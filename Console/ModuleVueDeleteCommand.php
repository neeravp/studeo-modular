<?php

namespace Studeo\Modular\Console;

use Studeo\Modular\Console\Actions\DeleteVueComponentAction;
use Studeo\Modular\Console\Prompts\ModulePicker;
use Studeo\Modular\Console\Prompts\VueComponentInputResolver;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\VueComponentDefinition;
use Studeo\Modular\DataObjects\VueDeletionResult;
use Studeo\Modular\Exceptions\NoTerminalException;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\warning;

class ModuleVueDeleteCommand extends ModuleCommand
{
    protected $signature = 'module:vue:delete
        {name? : The name of the Vue component to delete}
        {--module= : The name of the module}
        {--p|page : Specify that the component is an Inertia page}
        {--force : Force deletion without confirmation}';

    protected $aliases = ['module:page:delete', 'module:vue:rm'];

    protected $description = 'Delete a Vue component or Inertia page from a module';

    public function handle(
        VueComponentInputResolver $resolver,
        DeleteVueComponentAction $deleter,
        ModulePicker $picker,
    ): int {
        try {
            $module = $picker->resolve($this, $this->scalarOption('module'));
            $component = $resolver->resolve($this, $module, $this->stringArgument('name'), $this->forcedExample($module), promptVerb: 'delete', isPage: $this->isPageExplicit());

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
        DeleteVueComponentAction $deleter,
        ModuleDefinition $module,
        VueComponentDefinition $component,
    ): int {
        $result = $deleter->execute($module, $component->name, $component->isPage());
        $this->renderDeletionReport($result, $module);

        return self::SUCCESS;
    }

    private function cancelDeletion(VueComponentDefinition $component): int
    {
        $this->info("Component [{$component->name}] was not deleted.");

        return self::SUCCESS;
    }

    private function isPageExplicit(): ?bool
    {
        return $this->option('page') ? true : null;
    }

    private function confirmDeletion(ModuleDefinition $module, VueComponentDefinition $component): bool
    {
        if ($this->option('force')) {
            return true;
        }

        if (! $this->canPrompt()) {
            throw new NoTerminalException('the --force option', $this->forcedExample($module));
        }

        warning("This will permanently delete {$component->displayType()} [{$component->name}].");
        $this->line("<comment>File to be deleted:</comment> {$component->path}");

        return confirm(
            label: "Are you sure you want to delete component [{$component->name}]?",
            default: false,
        );
    }

    private function forcedExample(ModuleDefinition $module): string
    {
        return "{$this->getName()} Card --module={$module->name} --force";
    }

    private function renderDeletionReport(VueDeletionResult $result, ModuleDefinition $module): void
    {
        $this->info("{$result->component->displayType()} [{$result->component->name}] deleted successfully from module [{$module->name}].");
        $this->line("<comment>Deleted file:</comment> {$result->deletedFile}");
    }
}
