<?php

namespace Studeo\Modular\Console;

use Studeo\Modular\Console\Actions\CreateLivewireComponentAction;
use Studeo\Modular\Console\Prompts\ModulePicker;
use Studeo\Modular\Console\Prompts\RootAppChangeConfirmer;
use Studeo\Modular\DataObjects\LivewireComponentOptions;
use Studeo\Modular\DataObjects\LivewireGenerationResult;
use Studeo\Modular\Enums\LivewireComponentType;
use Throwable;

use function Laravel\Prompts\text;

class ModuleLivewireCommand extends ModuleCommand
{
    protected $signature = 'module:livewire
        {name? : The name of the Livewire component}
        {--module= : The name of the module}
        {--no-test : Do not generate a test}
        {--classic : Generate a classic class and view component (default)}
        {--mfc : Generate a Multi-File Component directory}
        {--sfc : Generate a Livewire 4 Single-File Component}
        {--inline : Generate an inline Livewire component}
        {--page : Generate a full-page component}
        {--layout= : Specify the layout for a full-page component}
        {--title= : Specify custom title for a full-page component}
        {--assets : Generate scoped CSS and JS files for MFC}
        {--schema= : A schema (@name, a file path, or inline): scaffolds a form component (single-file) with a Form class}
        {--action= : With --schema, the Action the form saves through, e.g. CreatePost; it is generated when missing}
        {--context=* : With --schema, where a #[Context] field of the Input comes from, as field=actor}';

    protected $description = 'Create a new Livewire component in a module';

    public function handle(CreateLivewireComponentAction $creator, RootAppChangeConfirmer $confirmer, ModulePicker $picker): int
    {
        try {
            $confirmer->ensureLivewireIsReady($this);

            $module = $picker->resolve($this, $this->scalarOption('module'));
            $name = $this->resolveNameArgument('What should the Livewire component be named?', 'e.g. PostList, Admin/UserTable', 'PostList');
            $options = $this->buildOptions();

            $result = $creator->execute($module, $name, $options);
        } catch (Throwable $exception) {
            return $this->reportFailure($exception);
        }

        $this->displaySuccess($result);

        return self::SUCCESS;
    }

    private function buildOptions(): LivewireComponentOptions
    {
        $type = $this->determineComponentType();
        $isPage = (bool) $this->option('page');
        $layout = $this->resolveLayoutOption($isPage);

        return new LivewireComponentOptions(
            type: $type,
            test: ! (bool) $this->option('no-test'),
            inline: (bool) $this->option('inline'),
            page: $isPage,
            layout: $layout,
            title: filled($this->stringOption('title')) ? (string) $this->stringOption('title') : null,
            assets: (bool) $this->option('assets'),
            schema: filled($this->stringOption('schema')) ? (string) $this->stringOption('schema') : null,
            action: filled($this->stringOption('action')) ? (string) $this->stringOption('action') : null,
            context: array_values((array) $this->option('context')),
        );
    }

    private function determineComponentType(): LivewireComponentType
    {
        return match (true) {
            (bool) $this->option('mfc') => LivewireComponentType::Mfc,
            (bool) $this->option('classic') => LivewireComponentType::Classic,
            (bool) $this->option('sfc') || $this->option('schema') !== null => LivewireComponentType::Sfc,
            default => LivewireComponentType::Classic,
        };
    }

    private function resolveLayoutOption(bool $isPage): ?string
    {
        if ($this->stringOption('layout')) {
            return (string) $this->stringOption('layout');
        }

        if ($this->shouldPromptForLayout($isPage)) {
            return text(
                label: 'Which layout should this page component use?',
                default: 'app',
                placeholder: 'e.g. app, admin, or accounts.admin',
            );
        }

        return null;
    }

    private function shouldPromptForLayout(bool $isPage): bool
    {
        return $isPage && $this->canPrompt();
    }

    private function displaySuccess(LivewireGenerationResult $result): void
    {
        $component = $result->component;
        $this->info("Livewire component [{$component->name}] created successfully in module [{$component->module->name}].");
        $this->line("<comment>Type:</comment> {$component->type}");
        $this->line('<comment>Aliases:</comment> '.implode(', ', $component->aliases()));
        $this->line('<comment>Created files:</comment>');

        foreach ($result->createdFiles as $file) {
            $this->line("  - {$file}");
        }

        foreach ($result->reusedFiles as $file) {
            $this->line("<comment>Reusing</comment> the existing {$file}");
        }

        array_map($this->line(...), $result->notices);
    }
}
