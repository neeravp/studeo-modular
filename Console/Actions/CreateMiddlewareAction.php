<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use Studeo\Modular\Console\Generation\ArtifactWriter;
use Studeo\Modular\Console\Generation\ModuleGuard;
use Studeo\Modular\Console\Generation\NameSanitizer;
use Studeo\Modular\Console\Generation\StubRenderer;
use Studeo\Modular\DataObjects\ModuleDefinition;

final class CreateMiddlewareAction
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly NameSanitizer $sanitizer,
        private readonly StubRenderer $stubRenderer,
        private readonly ModuleGuard $moduleGuard,
        private readonly ArtifactWriter $artifactWriter,
    ) {}

    public function execute(ModuleDefinition $module, string $name): string
    {
        $this->moduleGuard->ensureExists($module);

        $middlewareName = $this->sanitizer->className($name, 'middleware');
        $this->artifactWriter->ensureDoesNotExist($this->middlewarePath($module, $middlewareName), 'Middleware', $middlewareName, $module);

        return $this->createMiddlewareFile($module, $middlewareName);
    }

    private function createMiddlewareFile(ModuleDefinition $module, string $middlewareName): string
    {
        $path = $this->middlewarePath($module, $middlewareName);
        $this->files->ensureDirectoryExists(dirname($path));

        $content = $this->buildMiddlewareContent($module, $middlewareName);

        $this->files->put($path, $content);

        return $path;
    }

    private function buildMiddlewareContent(ModuleDefinition $module, string $middlewareName): string
    {
        $namespace = $module->namespace.'\\Middleware';
        $stub = $this->files->get($this->stubRenderer->path('Middleware.php.stub'));

        return str_replace(
            ['{{ namespace }}', '{{ class }}'],
            [$namespace, $middlewareName],
            $stub
        );
    }

    private function middlewarePath(ModuleDefinition $module, string $middlewareName): string
    {
        return $module->path.DIRECTORY_SEPARATOR.'Middleware'.DIRECTORY_SEPARATOR."{$middlewareName}.php";
    }
}
