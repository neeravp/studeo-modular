<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Studeo\Modular\Console\Generation\StubRenderer;
use Studeo\Modular\DataObjects\LivewireComponentOptions;
use Studeo\Modular\DataObjects\LivewireFormPlan;
use Studeo\Modular\DataObjects\LivewireGenerationResult;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Support\LivewireBladeResolver;
use Studeo\Modular\Support\LivewireFormContent;
use Studeo\Modular\Support\LivewireImports;
use Studeo\Modular\Support\LivewirePaths;
use Studeo\Modular\Support\PhpImports;

final class CreateLivewireSfcComponentAction
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly DiscoverLivewireComponentsAction $discoverer,
        private readonly EnsureLivewireLayoutExistsAction $layoutResolver,
        private readonly StubRenderer $stubRenderer,
        private readonly LivewirePaths $livewirePaths,
        private readonly LivewireImports $livewireImports,
    ) {}

    /**
     * @param  array<string>  $createdFiles
     */
    public function execute(
        ModuleDefinition $module,
        string $componentName,
        LivewireComponentOptions $options,
        array $createdFiles = [],
        ?LivewireFormPlan $form = null,
    ): LivewireGenerationResult {
        $sfcPath = $this->createSfcFile($module, $componentName, $options, $form);

        $createdFiles = array_values(array_filter([
            ...$createdFiles,
            $sfcPath,
            $this->createTestFile($module, $componentName, $options),
        ]));

        $component = $this->discoverer->resolveComponentDefinition(
            module: $module,
            name: $componentName,
            target: $sfcPath,
            path: $sfcPath,
            type: 'sfc',
            files: $createdFiles,
        );

        return new LivewireGenerationResult($component, $createdFiles);
    }

    private function createSfcFile(
        ModuleDefinition $module,
        string $componentName,
        LivewireComponentOptions $options,
        ?LivewireFormPlan $form,
    ): string {
        $path = $this->livewirePaths->resolveSfcPath($module, $componentName);
        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $this->buildSfcContent($module, $componentName, $options, $form));

        return $path;
    }

    private function createTestFile(
        ModuleDefinition $module,
        string $componentName,
        LivewireComponentOptions $options,
    ): ?string {
        if (! $options->test) {
            return null;
        }

        $path = $this->livewirePaths->resolveFeatureTestPath($module, $componentName);
        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $this->buildTestContent($module, $componentName));

        return $path;
    }

    private function buildSfcContent(
        ModuleDefinition $module,
        string $componentName,
        LivewireComponentOptions $options,
        ?LivewireFormPlan $form,
    ): string {
        $stub = $this->files->get($this->stubRenderer->path($this->resolveStubName($options)));
        $imports = new PhpImports('');
        $names = $this->livewireImports->names($imports, $options);
        $imports->add(...($form === null ? [] : LivewireFormContent::componentImports($form)));

        return str_replace(
            ['{{ title }}', '{{ imports }}', '{{ component }}', '{{ attributes }}', '    {{ classBody }}', '                {{ formFields }}'],
            [
                $options->title ?? Str::headline(basename($componentName)),
                $imports->statements(),
                $names['component'],
                $this->buildPageAttributes($module, $componentName, $options, $names),
                $form === null ? '' : LivewireFormContent::componentBody($form),
                $form === null ? '' : LivewireBladeResolver::resolve($form->action->fields, 'form.'),
            ],
            $stub
        );
    }

    public function resolveStubName(LivewireComponentOptions $options): string
    {
        return $options->hasSchema()
            ? 'livewire.sfc.form.blade.php.stub'
            : 'livewire.sfc.blade.php.stub';
    }

    private function buildTestContent(ModuleDefinition $module, string $componentName): string
    {
        return str_replace(
            '{{ alias }}',
            $this->livewirePaths->livewireAlias($module, $componentName),
            $this->files->get($this->stubRenderer->path('livewire.test.php.stub')),
        );
    }

    /**
     * @param  array<string, string>  $names
     */
    private function buildPageAttributes(
        ModuleDefinition $module,
        string $componentName,
        LivewireComponentOptions $options,
        array $names,
    ): string {
        if (! $options->page) {
            return '';
        }

        $title = $options->title ?? Str::headline(basename($componentName));
        $resolved = $this->layoutResolver->resolveLayout($module, $options->layout);

        return "#[{$names['layout']}('{$resolved->viewAttribute}')] #[{$names['title']}('{$title}')] ";
    }
}
