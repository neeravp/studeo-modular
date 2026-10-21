<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use Studeo\Modular\Console\Generation\ArtifactWriter;
use Studeo\Modular\Console\Generation\ModuleGuard;
use Studeo\Modular\Console\Generation\NameSanitizer;
use Studeo\Modular\Console\Generation\StubRenderer;
use Studeo\Modular\DataObjects\JobGenerationOptions;
use Studeo\Modular\DataObjects\ModuleDefinition;

final class CreateJobAction
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
        ?JobGenerationOptions $options = null,
    ): string {
        $options ??= new JobGenerationOptions;

        $this->moduleGuard->ensureExists($module);

        $jobName = $this->sanitizer->className($name, 'job');
        $this->artifactWriter->ensureDoesNotExist($this->jobPath($module, $jobName), 'Job', $jobName, $module);

        return $this->createJobFile($module, $jobName, $options);
    }

    private function createJobFile(
        ModuleDefinition $module,
        string $jobName,
        JobGenerationOptions $options,
    ): string {
        $path = $this->jobPath($module, $jobName);
        $this->files->ensureDirectoryExists(dirname($path));

        $content = $this->buildJobContent($module, $jobName, $options);

        $this->files->put($path, $content);

        return $path;
    }

    private function buildJobContent(
        ModuleDefinition $module,
        string $jobName,
        JobGenerationOptions $options,
    ): string {
        $namespace = $module->namespace.'\\Jobs';
        $stubFile = $this->resolveStubFile($options);
        $stub = $this->files->get($this->stubRenderer->path($stubFile));

        return str_replace(
            ['{{ namespace }}', '{{ class }}'],
            [$namespace, $jobName],
            $stub
        );
    }

    private function resolveStubFile(JobGenerationOptions $options): string
    {
        if ($options->batched) {
            return 'Job.batched.php.stub';
        }

        return $options->sync ? 'Job.sync.php.stub' : 'Job.queued.php.stub';
    }

    private function jobPath(ModuleDefinition $module, string $jobName): string
    {
        return $module->path.DIRECTORY_SEPARATOR.'Jobs'.DIRECTORY_SEPARATOR."{$jobName}.php";
    }
}
