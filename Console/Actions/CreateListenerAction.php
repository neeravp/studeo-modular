<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Queue\InteractsWithQueue;
use Studeo\Modular\Console\Generation\ArtifactWriter;
use Studeo\Modular\Console\Generation\ModuleGuard;
use Studeo\Modular\Console\Generation\NameSanitizer;
use Studeo\Modular\Console\Generation\StubRenderer;
use Studeo\Modular\DataObjects\ClassReference;
use Studeo\Modular\DataObjects\ListenerGenerationOptions;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Support\PhpImports;

final class CreateListenerAction
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
        ?ListenerGenerationOptions $options = null,
    ): string {
        $options ??= new ListenerGenerationOptions;

        $this->moduleGuard->ensureExists($module);

        $listenerName = $this->sanitizer->className($name, 'listener');
        $this->artifactWriter->ensureDoesNotExist($this->listenerPath($module, $listenerName), 'Listener', $listenerName, $module);

        $eventDefinition = $this->resolveEventDefinition($module, $options->event);

        return $this->createListenerFile($module, $listenerName, $eventDefinition, $options);
    }

    private function resolveEventDefinition(ModuleDefinition $module, ?string $event): ?ClassReference
    {
        if (blank($event)) {
            return null;
        }

        $reference = ClassReference::resolve($module, $event, 'Events');
        $this->sanitizer->ensureStartsWithLetter($reference->class, $event, 'event');

        return $reference;
    }

    private function createListenerFile(
        ModuleDefinition $module,
        string $listenerName,
        ?ClassReference $eventDefinition,
        ListenerGenerationOptions $options,
    ): string {
        $path = $this->listenerPath($module, $listenerName);
        $this->files->ensureDirectoryExists(dirname($path));

        $content = $this->buildListenerContent($module, $listenerName, $eventDefinition, $options);

        $this->files->put($path, $content);

        return $path;
    }

    private function buildListenerContent(
        ModuleDefinition $module,
        string $listenerName,
        ?ClassReference $eventDefinition,
        ListenerGenerationOptions $options,
    ): string {
        $stubFile = $options->queued ? 'Listener.queued.php.stub' : 'Listener.php.stub';
        $stub = $this->files->get($this->stubRenderer->path($stubFile));
        $imports = new PhpImports($module->namespace.'\\Listeners', $listenerName);
        if ($options->queued) {
            $imports->add(ShouldQueue::class, InteractsWithQueue::class);
        }
        $event = $eventDefinition === null ? 'object' : $imports->name($eventDefinition->fqcn);
        $statements = $imports->statements();

        return str_replace(
            ['{{ namespace }}', '{{ class }}', '{{ eventParam }}', '{{ imports }}'],
            [
                $module->namespace.'\\Listeners',
                $listenerName,
                "{$event} \$event",
                $statements === '' ? '' : $statements."\n\n",
            ],
            $stub
        );
    }

    private function listenerPath(ModuleDefinition $module, string $listenerName): string
    {
        return $module->path.DIRECTORY_SEPARATOR.'Listeners'.DIRECTORY_SEPARATOR."{$listenerName}.php";
    }
}
