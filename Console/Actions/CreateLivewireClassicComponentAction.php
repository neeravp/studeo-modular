<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Studeo\Modular\Console\Generation\StubRenderer;
use Studeo\Modular\DataObjects\LivewireComponentOptions;
use Studeo\Modular\DataObjects\LivewireGenerationResult;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\ModuleManifest;
use Studeo\Modular\Support\LivewireImports;
use Studeo\Modular\Support\LivewirePaths;
use Studeo\Modular\Support\PhpImports;

final class CreateLivewireClassicComponentAction
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
    ): LivewireGenerationResult {
        $classPath = $this->createClassFile($module, $componentName, $options);

        $createdFiles = array_values(array_filter([
            ...$createdFiles,
            $classPath,
            $this->createViewFile($module, $componentName, $options),
            $this->createTestFile($module, $componentName, $options),
        ]));

        $component = $this->discoverer->resolveComponentDefinition(
            module: $module,
            name: $componentName,
            target: $this->livewirePaths->buildClassicFqcn($module, $componentName),
            path: $classPath,
            type: 'classic',
            files: $createdFiles,
        );

        return new LivewireGenerationResult($component, $createdFiles);
    }

    private function createClassFile(
        ModuleDefinition $module,
        string $componentName,
        LivewireComponentOptions $options,
    ): string {
        $path = $this->livewirePaths->resolveClassicClassPath($module, $componentName);
        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $this->buildClassContent($module, $componentName, $options));

        return $path;
    }

    private function createViewFile(
        ModuleDefinition $module,
        string $componentName,
        LivewireComponentOptions $options,
    ): ?string {
        if ($options->inline) {
            return null;
        }

        $path = $this->livewirePaths->resolveClassicViewPath($module, $componentName);
        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $this->buildViewContent($componentName));

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

    private function buildClassContent(
        ModuleDefinition $module,
        string $componentName,
        LivewireComponentOptions $options,
    ): string {
        $stub = $this->files->get($this->stubRenderer->path('livewire.classic.php.stub'));
        $namespace = $this->livewirePaths->buildClassicNamespace($module, $componentName);
        $imports = new PhpImports($namespace, basename($componentName));
        $names = $this->livewireImports->names($imports, $options);

        return str_replace(
            ['{{ namespace }}', '{{ imports }}', '{{ attributes }}', '{{ class }}', '{{ component }}', '{{ renderContent }}'],
            [
                $namespace,
                $imports->statements(),
                $this->buildPageAttributes($module, $componentName, $options, $names),
                basename($componentName),
                $names['component'],
                $this->buildRenderContent($module, $componentName, $options),
            ],
            $stub
        );
    }

    private function buildViewContent(string $componentName): string
    {
        $stub = $this->files->get($this->stubRenderer->path('livewire.classic.blade.php.stub'));

        return str_replace('{{ name }}', basename($componentName), $stub);
    }

    private function buildTestContent(ModuleDefinition $module, string $componentName): string
    {
        return str_replace(
            '{{ alias }}',
            $this->livewirePaths->livewireAlias($module, $componentName),
            $this->files->get($this->stubRenderer->path('livewire.test.php.stub')),
        );
    }

    private function buildRenderContent(
        ModuleDefinition $module,
        string $componentName,
        LivewireComponentOptions $options,
    ): string {
        if ($options->inline) {
            return "<<<'HTML'\n        <div></div>\n        HTML";
        }

        $moduleKey = ModuleManifest::keyFor($module->name);
        $alias = $this->livewirePaths->formatKebabPath($componentName);

        return "view('{$moduleKey}::livewire.{$alias}')";
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

        return "#[{$names['layout']}('{$resolved->viewAttribute}')]\n#[{$names['title']}('{$title}')]\n";
    }
}
