<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use Studeo\Modular\Console\Generation\ArtifactWriter;
use Studeo\Modular\Console\Generation\ModuleGuard;
use Studeo\Modular\Console\Generation\NameSanitizer;
use Studeo\Modular\Console\Generation\StubRenderer;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\RuleGenerationOptions;

final class CreateRuleAction
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
        ?RuleGenerationOptions $options = null,
    ): string {
        $options ??= new RuleGenerationOptions;

        $this->moduleGuard->ensureExists($module);

        $ruleName = $this->sanitizer->className($name, 'rule');
        $this->artifactWriter->ensureDoesNotExist($this->rulePath($module, $ruleName), 'Rule', $ruleName, $module);

        return $this->createRuleFile($module, $ruleName, $options);
    }

    private function createRuleFile(
        ModuleDefinition $module,
        string $ruleName,
        RuleGenerationOptions $options,
    ): string {
        $path = $this->rulePath($module, $ruleName);
        $this->files->ensureDirectoryExists(dirname($path));

        $content = $this->buildRuleContent($module, $ruleName, $options);

        $this->files->put($path, $content);

        return $path;
    }

    private function buildRuleContent(
        ModuleDefinition $module,
        string $ruleName,
        RuleGenerationOptions $options,
    ): string {
        $namespace = $module->namespace.'\\Rules';
        $stubFile = $this->resolveStubFile($options);
        $stub = $this->files->get($this->stubRenderer->path($stubFile));

        return str_replace(
            ['{{ namespace }}', '{{ class }}'],
            [$namespace, $ruleName],
            $stub
        );
    }

    private function resolveStubFile(RuleGenerationOptions $options): string
    {
        return $options->implicit ? 'Rule.implicit.php.stub' : 'Rule.php.stub';
    }

    private function rulePath(ModuleDefinition $module, string $ruleName): string
    {
        return $module->path.DIRECTORY_SEPARATOR.'Rules'.DIRECTORY_SEPARATOR."{$ruleName}.php";
    }
}
