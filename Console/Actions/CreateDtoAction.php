<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use Studeo\Modular\Console\Generation\ArtifactWriter;
use Studeo\Modular\Console\Generation\ModuleGuard;
use Studeo\Modular\Console\Generation\NameSanitizer;
use Studeo\Modular\Console\Generation\StubRenderer;
use Studeo\Modular\DataObjects\DtoKind;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\SchemaDefinition;

final class CreateDtoAction
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly ResolveDtoKindAction $kinds,
        private readonly NameSanitizer $sanitizer,
        private readonly StubRenderer $stubRenderer,
        private readonly ModuleGuard $moduleGuard,
        private readonly ArtifactWriter $artifactWriter,
        private readonly ParseSchemaAction $schemaParser = new ParseSchemaAction,
        private readonly BuildSchemaDtoAction $schemaContent = new BuildSchemaDtoAction,
    ) {}

    public function execute(
        ModuleDefinition $module,
        string $name,
        string|SchemaDefinition|null $schema = null,
    ): string {
        $this->moduleGuard->ensureExists($module);

        $cleanName = $this->sanitizer->classPath($name, 'DTO');
        $kind = $this->kinds->execute($cleanName);
        $this->artifactWriter->ensureDoesNotExist($this->dtoPath($module, $cleanName), 'DTO', $cleanName, $module);

        $definition = $schema instanceof SchemaDefinition ? $schema : $this->schemaParser->execute($schema, $module)->forModule($module->namespace);

        return $this->createDtoFile($module, $cleanName, $kind, $definition);
    }

    private function createDtoFile(
        ModuleDefinition $module,
        string $name,
        DtoKind $kind,
        SchemaDefinition $schema,
    ): string {
        $path = $this->dtoPath($module, $name);
        $content = $this->buildDtoContent($module, $name, $kind, $schema);

        $this->artifactWriter->write($path, $content);

        return $path;
    }

    private function buildDtoContent(
        ModuleDefinition $module,
        string $name,
        DtoKind $kind,
        SchemaDefinition $schema,
    ): string {
        $segments = explode('/', $name);
        $className = array_pop($segments);
        $subNamespace = count($segments) > 0 ? '\\'.implode('\\', $segments) : '';

        $namespace = $module->namespace.'\\Data'.$subNamespace;
        $stub = $this->files->get($this->stubRenderer->path($kind->stub()));

        $content = str_replace(
            ['{{ namespace }}', '{{ class }}'],
            [$namespace, $className],
            $stub
        );

        return $schema->hasFields() ? $this->schemaContent->execute($content, $module, $schema, $kind) : $content;
    }

    private function dtoPath(ModuleDefinition $module, string $name): string
    {
        return $module->path.DIRECTORY_SEPARATOR.'Data'.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $name).'.php';
    }
}
