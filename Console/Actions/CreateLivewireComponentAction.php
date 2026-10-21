<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Support\Str;
use RuntimeException;
use Studeo\Modular\Console\Generation\ModuleGuard;
use Studeo\Modular\Console\Generation\NameSanitizer;
use Studeo\Modular\DataObjects\LivewireComponentOptions;
use Studeo\Modular\DataObjects\LivewireFormPlan;
use Studeo\Modular\DataObjects\LivewireGenerationResult;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\SchemaDefinition;
use Studeo\Modular\Enums\LivewireComponentType;

final class CreateLivewireComponentAction
{
    public function __construct(
        private readonly DiscoverLivewireComponentsAction $discoverer,
        private readonly EnsureLivewireLayoutExistsAction $layoutEnsurer,
        private readonly CreateLivewireClassicComponentAction $classicCreator,
        private readonly CreateLivewireMfcComponentAction $mfcCreator,
        private readonly CreateLivewireSfcComponentAction $sfcCreator,
        private readonly ParseSchemaAction $schemaParser,
        private readonly PlanLivewireFormAction $formPlanner,
        private readonly WriteLivewireFormAction $formWriter,
        private readonly NameSanitizer $sanitizer,
        private readonly ModuleGuard $moduleGuard,
    ) {}

    public function execute(
        ModuleDefinition $module,
        string $name,
        ?LivewireComponentOptions $options = null,
    ): LivewireGenerationResult {
        $options ??= new LivewireComponentOptions;

        $this->moduleGuard->ensureExists($module);

        $componentName = $this->resolveNewComponentName($module, $name, $options);
        $schema = $this->formSchema($module, $options);
        $plan = $this->planForm($module, $componentName, $schema, $options);
        $layoutFiles = $this->ensurePageLayout($module, $options);
        $form = $this->writeForm($module, $plan, $schema);

        return $this->generateComponent($module, $componentName, $options, $layoutFiles, $plan, $form);
    }

    private function resolveNewComponentName(ModuleDefinition $module, string $name, LivewireComponentOptions $options): string
    {
        $componentName = $this->resolveTargetComponentName($name, $options);
        $this->ensureComponentDoesNotExist($module, $componentName);

        return $componentName;
    }

    private function formSchema(ModuleDefinition $module, LivewireComponentOptions $options): ?SchemaDefinition
    {
        if (! $this->wantsForm($options)) {
            return null;
        }

        return $this->parsedSchema($module, $options);
    }

    private function wantsForm(LivewireComponentOptions $options): bool
    {
        return $options->isSfc() && $options->hasSchema();
    }

    private function planForm(ModuleDefinition $module, string $componentName, ?SchemaDefinition $schema, LivewireComponentOptions $options): ?LivewireFormPlan
    {
        if ($schema === null) {
            return null;
        }

        return $this->formPlanner->execute($module, $componentName, $schema, $options->action, $options->context);
    }

    /**
     * @return array<int, string> the layout file, when it had to be created
     */
    private function ensurePageLayout(ModuleDefinition $module, LivewireComponentOptions $options): array
    {
        if (! $options->page) {
            return [];
        }

        $layout = $this->layoutEnsurer->execute($module, $options->layout);

        return $layout->wasCreated ? [$layout->filePath] : [];
    }

    /**
     * @return array{created: array<int, string>, reused: array<int, string>, notices: array<int, string>}|null
     */
    private function writeForm(ModuleDefinition $module, ?LivewireFormPlan $plan, ?SchemaDefinition $schema): ?array
    {
        if ($plan === null) {
            return null;
        }

        return $this->formWriter->execute($module, $plan, $schema);
    }

    /**
     * @param  array<int, string>  $layoutFiles
     * @param  array{created: array<int, string>, reused: array<int, string>, notices: array<int, string>}|null  $form
     */
    private function generateComponent(ModuleDefinition $module, string $componentName, LivewireComponentOptions $options, array $layoutFiles, ?LivewireFormPlan $plan, ?array $form): LivewireGenerationResult
    {
        $result = match ($options->type) {
            LivewireComponentType::Classic => $this->classicCreator->execute($module, $componentName, $options, $layoutFiles),
            LivewireComponentType::Mfc => $this->mfcCreator->execute($module, $componentName, $options, $layoutFiles),
            LivewireComponentType::Sfc => $this->sfcCreator->execute($module, $componentName, $options, [...$layoutFiles, ...($form['created'] ?? [])], $plan),
        };

        return $this->withFormOutcome($result, $form);
    }

    /**
     * @param  array{created: array<int, string>, reused: array<int, string>, notices: array<int, string>}|null  $form
     */
    private function withFormOutcome(LivewireGenerationResult $result, ?array $form): LivewireGenerationResult
    {
        if ($form === null) {
            return $result;
        }

        return new LivewireGenerationResult($result->component, $result->createdFiles, $form['reused'], $form['notices']);
    }

    private function parsedSchema(ModuleDefinition $module, LivewireComponentOptions $options): SchemaDefinition
    {
        return $options->schemaDefinition ?? $this->schemaParser->execute($options->schema, $module)->forModule($module->namespace);
    }

    private function resolveTargetComponentName(string $name, LivewireComponentOptions $options): string
    {
        $clean = $this->sanitizer->classPath($name, 'component');

        $this->ensureClassicClassIsLoadable($clean, $name, $options);

        if ($this->isPageOutsidePagesFolder($clean, $options)) {
            return 'Pages/'.$clean;
        }

        return $clean;
    }

    private function ensureClassicClassIsLoadable(string $clean, string $name, LivewireComponentOptions $options): void
    {
        if (! $options->isClassic()) {
            return;
        }

        $this->sanitizer->ensureStartsWithLetter(basename($clean), $name, 'component');
    }

    private function isPageOutsidePagesFolder(string $clean, LivewireComponentOptions $options): bool
    {
        return $options->page && ! Str::startsWith(strtolower($clean), 'pages/');
    }

    private function ensureComponentDoesNotExist(ModuleDefinition $module, string $componentName): void
    {
        $existing = $this->discoverer->find($module, $componentName);

        if ($existing !== null) {
            throw new RuntimeException("Livewire component [{$componentName}] already exists in module [{$module->name}].");
        }
    }
}
