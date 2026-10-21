<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Studeo\Modular\Console\Generation\ArtifactWriter;
use Studeo\Modular\Console\Generation\ModuleGuard;
use Studeo\Modular\Console\Generation\NameSanitizer;
use Studeo\Modular\Console\Generation\StubRenderer;
use Studeo\Modular\DataObjects\ModuleDefinition;

final class CreateResourceAction
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly NameSanitizer $sanitizer,
        private readonly StubRenderer $stubRenderer,
        private readonly ModuleGuard $moduleGuard,
        private readonly ArtifactWriter $artifactWriter,
    ) {}

    public function execute(
        ModuleDefinition $module,
        string $name,
        bool $collection = false,
    ): string {
        $isCollection = $this->isCollection($name, $collection);
        $suffix = $isCollection ? 'Collection' : 'Resource';
        $resourceName = $this->sanitizer->className($name, 'resource', $suffix);

        $this->moduleGuard->ensureExists($module);
        $this->artifactWriter->ensureDoesNotExist($this->resourcePath($module, $resourceName), 'Resource', $resourceName, $module);

        return $this->createResource($module, $resourceName, $isCollection);
    }

    /**
     * A collection when asked for, or when the name already ends in Collection.
     */
    private function isCollection(string $name, bool $collection): bool
    {
        return $collection || Str::endsWith(Str::studly($name), 'Collection');
    }

    private function createResource(
        ModuleDefinition $module,
        string $resourceName,
        bool $isCollection,
    ): string {
        $path = $this->resourcePath($module, $resourceName);
        $this->files->ensureDirectoryExists(dirname($path));

        $content = $this->buildResourceContent($module, $resourceName, $isCollection);

        $this->files->put($path, $content);

        return $path;
    }

    private function buildResourceContent(
        ModuleDefinition $module,
        string $resourceName,
        bool $isCollection,
    ): string {
        $stub = $isCollection
            ? $this->files->get($this->stubRenderer->path('ResourceCollection.php.stub'))
            : $this->files->get($this->stubRenderer->path('Resource.php.stub'));

        return str_replace(
            ['{{ namespace }}', '{{ class }}'],
            [$module->namespace.'\\ApiResources', $resourceName],
            $stub
        );
    }

    private function resourcePath(ModuleDefinition $module, string $resourceName): string
    {
        return $module->path.DIRECTORY_SEPARATOR.'ApiResources'.DIRECTORY_SEPARATOR."{$resourceName}.php";
    }
}
