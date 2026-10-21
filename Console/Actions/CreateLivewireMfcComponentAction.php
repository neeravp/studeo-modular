<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Studeo\Modular\Console\Generation\StubRenderer;
use Studeo\Modular\DataObjects\LivewireComponentOptions;
use Studeo\Modular\DataObjects\LivewireGenerationResult;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Support\LivewireImports;
use Studeo\Modular\Support\LivewirePaths;
use Studeo\Modular\Support\PhpImports;

final class CreateLivewireMfcComponentAction
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
     * Writes the multi-file component as Livewire 4 lays it out: a ⚡{name} directory holding
     * {name}.php (an anonymous class), {name}.blade.php, and optionally {name}.test.php,
     * {name}.css and {name}.js.
     *
     * @param  array<string>  $createdFiles
     */
    public function execute(
        ModuleDefinition $module,
        string $componentName,
        LivewireComponentOptions $options,
        array $createdFiles = [],
    ): LivewireGenerationResult {
        $dir = $this->livewirePaths->resolveMfcDirectory($module, $componentName);
        $base = $dir.DIRECTORY_SEPARATOR.$this->livewirePaths->mfcFileBase($componentName);
        $this->files->ensureDirectoryExists($dir);

        $createdFiles = array_values(array_filter([
            ...$createdFiles,
            $this->write("{$base}.php", $this->buildClassContent($module, $componentName, $options)),
            $this->write("{$base}.blade.php", $this->files->get($this->stubRenderer->path('livewire.mfc.blade.php.stub'))),
            $options->test ? $this->write("{$base}.test.php", $this->buildTestContent($module, $componentName)) : null,
            $options->assets ? $this->write("{$base}.css", '') : null,
            $options->assets ? $this->write("{$base}.js", '') : null,
        ]));

        $component = $this->discoverer->resolveComponentDefinition(
            module: $module,
            name: $componentName,
            target: $dir,
            path: $dir,
            type: 'mfc',
            files: $createdFiles,
        );

        return new LivewireGenerationResult($component, $createdFiles);
    }

    private function write(string $path, string $content): string
    {
        $this->files->put($path, $content);

        return $path;
    }

    private function buildClassContent(
        ModuleDefinition $module,
        string $componentName,
        LivewireComponentOptions $options,
    ): string {
        $imports = new PhpImports('');
        $names = $this->livewireImports->names($imports, $options);

        return str_replace(
            ['{{ imports }}', '{{ attributes }}', '{{ component }}'],
            [$imports->statements(), $this->buildPageAttributes($module, $componentName, $options, $names), $names['component']],
            $this->files->get($this->stubRenderer->path('livewire.mfc.php.stub')),
        );
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
    private function buildPageAttributes(ModuleDefinition $module, string $componentName, LivewireComponentOptions $options, array $names): string
    {
        if (! $options->page) {
            return '';
        }

        $title = $options->title ?? Str::headline(basename($componentName));
        $resolved = $this->layoutResolver->resolveLayout($module, $options->layout);

        return "#[{$names['layout']}('{$resolved->viewAttribute}')] #[{$names['title']}('{$title}')] ";
    }
}
