<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use Studeo\Modular\Console\Generation\ArtifactWriter;
use Studeo\Modular\Console\Generation\ModuleGuard;
use Studeo\Modular\Console\Generation\StubRenderer;
use Studeo\Modular\DataObjects\ModuleDefinition;

/**
 * Writes Enums/{Name}.php: a string-backed enum with one case per value and nothing else. The
 * name and the cases are checked before anything is written, and an existing file is never replaced.
 */
final class CreateEnumAction
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly ResolveEnumNameAction $names,
        private readonly ResolveEnumCasesAction $cases,
        private readonly StubRenderer $stubRenderer,
        private readonly ModuleGuard $moduleGuard,
        private readonly ArtifactWriter $artifactWriter,
    ) {}

    public function execute(ModuleDefinition $module, string $name, string $cases): string
    {
        $this->moduleGuard->ensureExists($module);

        $enum = $this->names->execute($name);
        $values = $this->cases->execute($cases);
        $path = "{$module->path}/Enums/{$enum}.php";

        $this->artifactWriter->ensureDoesNotExist($path, 'Enum', $enum, $module);

        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, str_replace(
            ['{{ namespace }}', '{{ class }}', '{{ cases }}'],
            [$module->namespace.'\\Enums', $enum, $this->caseLines($values)],
            $this->files->get($this->stubRenderer->path('enum.stub')),
        ));

        return $path;
    }

    /**
     * @param  array<string, string>  $values
     */
    private function caseLines(array $values): string
    {
        return implode("\n", array_map(fn (string $name, string $value): string => "    case {$name} = '{$value}';", array_keys($values), $values));
    }
}
