<?php

namespace Studeo\Modular\Console;

use Studeo\Modular\Console\Actions\CreateVueComponentAction;
use Studeo\Modular\Console\Prompts\ModulePicker;
use Studeo\Modular\Console\Prompts\RootAppChangeConfirmer;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\VueComponentOptions;
use Studeo\Modular\DataObjects\VueGenerationResult;
use Studeo\Modular\Enums\VueComponentType;
use Throwable;

use function Laravel\Prompts\select;

class ModuleVueCommand extends ModuleCommand
{
    protected $signature = 'module:vue
        {name? : The name of the Vue component}
        {--module= : The name of the module}
        {--p|page : Generate an Inertia full page component}
        {--f|form : Generate an Inertia form page component}
        {--schema= : Schema definition or path to schema file for form scaffolding}
        {--action= : With --form or --schema, the Action the form saves through, e.g. CreatePost; it is generated when missing}
        {--url= : With --form or --schema, the URL the form posts to, e.g. /posts}';

    protected $aliases = ['module:page'];

    protected $description = 'Create a new Vue or Inertia component in a module';

    public function handle(CreateVueComponentAction $creator, RootAppChangeConfirmer $confirmer, ModulePicker $picker): int
    {
        try {
            $confirmer->ensureInertiaVueIsReady($this);
            $confirmer->ensureViteIsConfigured($this);

            $module = $picker->resolve($this, $this->scalarOption('module'));
            $name = $this->resolveNameArgument('What is the name of the Vue component?', 'e.g. PostCard or Posts/Index', 'PostCard');
            $options = $this->buildOptions();

            $result = $creator->execute($module, $name, $options);
        } catch (Throwable $exception) {
            return $this->reportFailure($exception);
        }

        $this->displaySuccess($module, $name, $result, $options);

        return self::SUCCESS;
    }

    private function buildOptions(): VueComponentOptions
    {
        return new VueComponentOptions(
            type: $this->determineComponentType(),
            schema: filled($this->stringOption('schema')) ? (string) $this->stringOption('schema') : null,
            action: filled($this->stringOption('action')) ? (string) $this->stringOption('action') : null,
            url: filled($this->stringOption('url')) ? (string) $this->stringOption('url') : null,
        );
    }

    private function determineComponentType(): VueComponentType
    {
        return match (true) {
            (bool) $this->option('form') || $this->option('schema') !== null => VueComponentType::Form,
            (bool) $this->option('page') || $this->isPageAlias() => VueComponentType::Page,
            $this->shouldPromptForType() => $this->promptForComponentType(),
            default => VueComponentType::Component,
        };
    }

    private function isPageAlias(): bool
    {
        return $this->input->getFirstArgument() === 'module:page';
    }

    private function shouldPromptForType(): bool
    {
        return $this->argument('name') === null && $this->canPrompt();
    }

    private function promptForComponentType(): VueComponentType
    {
        $choice = select(
            label: 'What type of Vue component would you like to create?',
            options: [
                VueComponentType::Component->value => 'Embedded UI Component (Components/)',
                VueComponentType::Page->value => 'Inertia Full Page (Pages/)',
                VueComponentType::Form->value => 'Inertia Form Page with useForm() (Pages/)',
            ],
            default: VueComponentType::Component->value,
        );

        return VueComponentType::from($choice);
    }

    private function displaySuccess(
        ModuleDefinition $module,
        string $name,
        VueGenerationResult $result,
        VueComponentOptions $options,
    ): void {
        $typeLabel = $this->resolveTypeLabel($options->type);

        $this->info("Vue {$typeLabel} [{$name}] created successfully in module [{$module->name}].");
        $this->line('<comment>Created files:</comment>');

        foreach ($result->createdFiles as $file) {
            $this->line("  - {$file}");
        }

        foreach ($result->reusedFiles as $file) {
            $this->line("<comment>Reusing</comment> the existing {$file}");
        }

        array_map($this->line(...), $result->notices);

        if ($options->isPage()) {
            $this->line("<comment>Render in Controller:</comment> Inertia::render('{$module->name}::{$name}');");
        }
    }

    private function resolveTypeLabel(VueComponentType $type): string
    {
        return match ($type) {
            VueComponentType::Form => 'Form Page',
            VueComponentType::Page => 'Page',
            default => 'Component',
        };
    }
}
