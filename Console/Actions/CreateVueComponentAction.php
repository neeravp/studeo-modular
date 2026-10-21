<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Studeo\Modular\Console\Generation\ArtifactWriter;
use Studeo\Modular\Console\Generation\ModuleGuard;
use Studeo\Modular\Console\Generation\NameSanitizer;
use Studeo\Modular\Console\Generation\StubRenderer;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\SchemaDefinition;
use Studeo\Modular\DataObjects\VueComponentOptions;
use Studeo\Modular\DataObjects\VueFormPlan;
use Studeo\Modular\DataObjects\VueGenerationResult;
use Studeo\Modular\Support\VueFormResolver;
use Studeo\Modular\Support\VueFormSubmit;

final class CreateVueComponentAction
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly PlanVueFormAction $formPlanner,
        private readonly WriteFormActionAction $formWriter,
        private readonly NameSanitizer $sanitizer,
        private readonly StubRenderer $stubRenderer,
        private readonly ModuleGuard $moduleGuard,
        private readonly ArtifactWriter $artifactWriter,
        private readonly ParseSchemaAction $schemaParser = new ParseSchemaAction,
    ) {}

    public function execute(
        ModuleDefinition $module,
        string $name,
        ?VueComponentOptions $options = null,
    ): VueGenerationResult {
        $options ??= new VueComponentOptions;
        $componentName = $this->sanitizer->classPath($name, 'component');

        $this->ensureCanCreateComponent($module, $componentName, $options);

        $schema = $options->hasSchema() ? $this->resolveSchema($options, $module) : null;
        $plan = $this->formPlanner->execute($module, $schema, $options);
        $form = $plan === null ? null : $this->formWriter->execute($module, $plan->action, $schema);

        $path = $this->createVueFile($module, $componentName, $options, $plan);

        return new VueGenerationResult($path, [...($form['created'] ?? []), $path], $form['reused'] ?? [], $form['notices'] ?? []);
    }

    private function ensureCanCreateComponent(
        ModuleDefinition $module,
        string $name,
        VueComponentOptions $options,
    ): void {
        $this->moduleGuard->ensureExists($module);

        $artifactType = $options->isPage() ? 'Vue Page' : 'Vue Component';
        $this->artifactWriter->ensureDoesNotExist(
            $this->componentPath($module, $name, $options),
            $artifactType,
            $name,
            $module
        );
    }

    private function resolveSchema(VueComponentOptions $options, ModuleDefinition $module): SchemaDefinition
    {
        return $options->schemaDefinition
            ?? $this->schemaParser->execute($options->schema, $module)->forModule($module->namespace);
    }

    private function createVueFile(
        ModuleDefinition $module,
        string $name,
        VueComponentOptions $options,
        ?VueFormPlan $plan,
    ): string {
        $path = $this->componentPath($module, $name, $options);
        $this->files->ensureDirectoryExists(dirname($path));

        $this->files->put($path, $this->buildComponentContent($module, $name, $options, $plan));

        return $path;
    }

    private function buildComponentContent(
        ModuleDefinition $module,
        string $name,
        VueComponentOptions $options,
        ?VueFormPlan $plan,
    ): string {
        $className = basename($name);
        $fields = $plan->action->fields ?? [];

        return str_replace(
            ['{{ name }}', '{{ class }}', '{{ module }}', '{{ submit }}', "{{ formType }}\n", "{{ formState }}\n", "{{ formFields }}\n"],
            [
                $className,
                Str::kebab($className),
                $module->name,
                VueFormSubmit::render($fields, $plan->url ?? ''),
                VueFormResolver::resolveFormType($fields),
                VueFormResolver::resolveFormState($fields),
                VueFormResolver::resolveFormFields($fields),
            ],
            $this->files->get($this->stubRenderer->path($this->resolveStubName($options)))
        );
    }

    public function resolveStubName(VueComponentOptions $options): string
    {
        return match (true) {
            $options->isForm() => 'vue.form.stub',
            $options->isPage() => 'vue.page.stub',
            default => 'vue.component.stub',
        };
    }

    private function componentPath(
        ModuleDefinition $module,
        string $name,
        VueComponentOptions $options,
    ): string {
        $subfolder = $options->isPage() ? 'Pages' : 'Components';
        $relativeFile = str_replace('/', DIRECTORY_SEPARATOR, $name).'.vue';

        return $module->path.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'js'.DIRECTORY_SEPARATOR.$subfolder.DIRECTORY_SEPARATOR.$relativeFile;
    }
}
