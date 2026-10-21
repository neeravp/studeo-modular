<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use Studeo\Modular\Console\Generation\ArtifactWriter;
use Studeo\Modular\Console\Generation\ModuleGuard;
use Studeo\Modular\Console\Generation\NameSanitizer;
use Studeo\Modular\Console\Generation\StubRenderer;
use Studeo\Modular\DataObjects\ModuleDefinition;

final class CreateEventAction
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
    ): string {
        $this->moduleGuard->ensureExists($module);

        $eventName = $this->sanitizer->className($name, 'event');
        $this->artifactWriter->ensureDoesNotExist($this->eventPath($module, $eventName), 'Event', $eventName, $module);

        return $this->createEventFile($module, $eventName);
    }

    private function createEventFile(
        ModuleDefinition $module,
        string $eventName,
    ): string {
        $path = $this->eventPath($module, $eventName);
        $this->files->ensureDirectoryExists(dirname($path));

        $content = $this->buildEventContent($module, $eventName);

        $this->files->put($path, $content);

        return $path;
    }

    private function buildEventContent(
        ModuleDefinition $module,
        string $eventName,
    ): string {
        $namespace = $module->namespace.'\\Events';
        $stub = $this->files->get($this->stubRenderer->path('Event.php.stub'));

        return str_replace(
            ['{{ namespace }}', '{{ class }}'],
            [$namespace, $eventName],
            $stub
        );
    }

    private function eventPath(ModuleDefinition $module, string $eventName): string
    {
        return $module->path.DIRECTORY_SEPARATOR.'Events'.DIRECTORY_SEPARATOR."{$eventName}.php";
    }
}
