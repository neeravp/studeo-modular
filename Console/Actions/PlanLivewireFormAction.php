<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;
use Studeo\Modular\DataObjects\LivewireFormPlan;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\SchemaDefinition;

/**
 * Decides, before anything is written, what a Livewire form needs: the Action it saves through
 * (--action), that Action's paired Input, the Form's path, the fields the Form carries and where
 * each #[Context] value comes from (--context=field=actor). It throws on the first problem, so a
 * failed plan leaves the module untouched.
 */
final class PlanLivewireFormAction
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly PlanFormActionAction $actions,
    ) {}

    /**
     * @param  array<int, string>  $contextOptions  --context values, each field=actor
     *
     * @throws RuntimeException
     */
    public function execute(ModuleDefinition $module, string $componentName, SchemaDefinition $schema, ?string $action, array $contextOptions): LivewireFormPlan
    {
        if ($action === null) {
            throw new RuntimeException('A schema needs the Action its form saves through. Pass --action, e.g. --action=CreateArticle, and --context=field=actor for each context field the Input has.');
        }

        $formPath = $this->formPath($module, $componentName);

        if ($this->files->isFile($formPath)) {
            throw new RuntimeException('Form ['.Str::after($formPath, $module->path.'/').'] already exists in module ['.$module->name.']; nothing was written.');
        }

        $plan = $this->actions->execute($module, $schema, $action);
        $this->actions->checkContext($contextOptions, $plan->context);

        return new LivewireFormPlan($plan, $this->formClass($module, $componentName), $formPath);
    }

    public function formClass(ModuleDefinition $module, string $componentName): string
    {
        return $module->namespace.'\\Livewire\\Forms\\'.str_replace('/', '\\', $this->formName($componentName));
    }

    public function formPath(ModuleDefinition $module, string $componentName): string
    {
        return $module->path.'/Livewire/Forms/'.$this->formName($componentName).'.php';
    }

    private function formName(string $componentName): string
    {
        $directory = dirname($componentName);

        return ($directory === '.' ? '' : $directory.'/').Str::studly(basename($componentName)).'Form';
    }
}
