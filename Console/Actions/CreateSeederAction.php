<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use Studeo\Modular\Console\Generation\ArtifactWriter;
use Studeo\Modular\Console\Generation\ModuleGuard;
use Studeo\Modular\Console\Generation\NameSanitizer;
use Studeo\Modular\Console\Generation\StubRenderer;
use Studeo\Modular\DataObjects\ClassReference;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Support\PhpImports;
use Studeo\Modular\Support\Schema\ModuleModels;

final class CreateSeederAction
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly NameSanitizer $sanitizer,
        private readonly StubRenderer $stubRenderer,
        private readonly ModuleGuard $moduleGuard,
        private readonly ArtifactWriter $artifactWriter,
        private readonly ModuleModels $models,
    ) {}

    public function execute(
        ModuleDefinition $module,
        string $name,
        ?string $model = null,
    ): string {
        $this->moduleGuard->ensureExists($module);

        $seederName = $this->sanitizer->className($name, 'seeder', 'Seeder');
        $this->artifactWriter->ensureDoesNotExist($this->seederPath($module, $seederName), 'Seeder', $seederName, $module);

        $modelDefinition = $this->resolveModelDefinition($module, $model);

        return $this->createSeederFile($module, $seederName, $modelDefinition);
    }

    private function resolveModelDefinition(
        ModuleDefinition $module,
        ?string $model,
    ): ?ClassReference {
        if (blank($model)) {
            return null;
        }

        $reference = ClassReference::resolve($module, $model, 'Models');
        $this->sanitizer->ensureStartsWithLetter($reference->class, $model, 'model');

        return $reference;
    }

    private function createSeederFile(
        ModuleDefinition $module,
        string $seederName,
        ?ClassReference $modelDefinition,
    ): string {
        $path = $this->seederPath($module, $seederName);
        $this->files->ensureDirectoryExists(dirname($path));

        $content = $this->buildSeederContent($module, $seederName, $modelDefinition);

        $this->files->put($path, $content);

        return $path;
    }

    private function buildSeederContent(
        ModuleDefinition $module,
        string $seederName,
        ?ClassReference $modelDefinition,
    ): string {
        $seederNamespace = $module->namespace.'\\Database\\Seeders';
        $imports = new PhpImports($seederNamespace, $seederName);

        $replacements = [
            '{{ seederNamespace }}' => $seederNamespace,
            '{{ seeder }}' => $seederName,
            '{{ base }}' => $imports->name('Illuminate\\Database\\Seeder'),
            '{{ body }}' => $this->hasModuleFactory($module, $modelDefinition)
                ? "\n    {\n        {$imports->name($modelDefinition->fqcn)}::factory()->count(10)->create();\n    }"
                : ' {}',
        ];

        return str_replace(
            [...array_keys($replacements), '{{ imports }}'],
            [...array_values($replacements), $imports->statements()],
            $this->files->get($this->stubRenderer->path('Seeder.php.stub'))
        );
    }

    /**
     * Whether the seeder can create its model through a factory: the model is one of the module's
     * and its factory exists. Otherwise the seeder's run() is empty and imports no model.
     *
     * @phpstan-assert-if-true ClassReference $modelDefinition
     */
    private function hasModuleFactory(ModuleDefinition $module, ?ClassReference $modelDefinition): bool
    {
        return $modelDefinition !== null
            && $modelDefinition->namespace === "{$module->namespace}\\Models"
            && $this->models->hasFactory($module, $modelDefinition->class);
    }

    public function seederPath(ModuleDefinition $module, string $seederName): string
    {
        return $module->path.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'seeders'.DIRECTORY_SEPARATOR."{$seederName}.php";
    }
}
