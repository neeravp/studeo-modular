<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Studeo\Modular\Console\Generation\ArtifactWriter;
use Studeo\Modular\Console\Generation\ModuleGuard;
use Studeo\Modular\Console\Generation\NameSanitizer;
use Studeo\Modular\Console\Generation\StubRenderer;
use Studeo\Modular\DataObjects\CommandGenerationOptions;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\ModuleManifest;

final class CreateCommandAction
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
        ?CommandGenerationOptions $options = null,
    ): string {
        $options ??= new CommandGenerationOptions;

        $this->moduleGuard->ensureExists($module);

        $commandName = $this->sanitizer->className($name, 'command');
        $this->artifactWriter->ensureDoesNotExist($this->commandPath($module, $commandName), 'Command', $commandName, $module);

        $signature = $this->resolveSignature($module, $commandName, $options->command);

        return $this->createCommandFile($module, $commandName, $signature);
    }

    private function resolveSignature(
        ModuleDefinition $module,
        string $commandName,
        ?string $explicitCommand,
    ): string {
        if (filled($explicitCommand)) {
            return trim($explicitCommand);
        }

        $modulePrefix = ModuleManifest::keyFor($module->name);
        $commandKebab = Str::kebab($this->withoutCommandSuffix($commandName));

        return "{$modulePrefix}:{$commandKebab}";
    }

    /**
     * The name without a trailing Command, unless that would leave nothing.
     */
    private function withoutCommandSuffix(string $commandName): string
    {
        return Str::endsWith($commandName, 'Command') && $commandName !== 'Command'
            ? Str::beforeLast($commandName, 'Command')
            : $commandName;
    }

    private function createCommandFile(
        ModuleDefinition $module,
        string $commandName,
        string $signature,
    ): string {
        $path = $this->commandPath($module, $commandName);
        $this->files->ensureDirectoryExists(dirname($path));

        $content = $this->buildCommandContent($module, $commandName, $signature);

        $this->files->put($path, $content);

        return $path;
    }

    private function buildCommandContent(
        ModuleDefinition $module,
        string $commandName,
        string $signature,
    ): string {
        $namespace = $module->namespace.'\\Commands';
        $stub = $this->files->get($this->stubRenderer->path('Command.php.stub'));

        return str_replace(
            ['{{ namespace }}', '{{ class }}', '{{ command }}'],
            [$namespace, $commandName, $signature],
            $stub
        );
    }

    private function commandPath(ModuleDefinition $module, string $commandName): string
    {
        return $module->path.DIRECTORY_SEPARATOR.'Commands'.DIRECTORY_SEPARATOR."{$commandName}.php";
    }
}
