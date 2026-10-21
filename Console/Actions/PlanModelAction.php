<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Studeo\Modular\DataObjects\ModelGenerationOptions;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Decides, before anything is written, whether every file module:model will write is free: the
 * model, and the migration, factory and seeder unless they are opted out. A file that is opted
 * out is never looked at. It throws once, naming every conflict, so a failed plan leaves the
 * module untouched.
 */
final class PlanModelAction
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly CreateFactoryAction $factories,
        private readonly CreateSeederAction $seeders,
    ) {}

    /**
     * @throws RuntimeException
     */
    public function execute(ModuleDefinition $module, string $modelName, string $tableName, ModelGenerationOptions $options): void
    {
        $conflicts = array_values(array_filter([
            $this->files->isFile($this->modelPath($module, $modelName)) ? "Model [{$modelName}] already exists in module [{$module->name}]." : null,
            $options->withMigration ? $this->migrationConflict($module, $tableName) : null,
            $options->withFactory ? $this->factoryConflict($module, $modelName) : null,
            $options->withSeeder ? $this->seederConflict($module, $modelName) : null,
        ]));

        if ($conflicts !== []) {
            throw new RuntimeException(implode("\n", [...$conflicts, 'Nothing was written.']));
        }
    }

    public function modelPath(ModuleDefinition $module, string $modelName): string
    {
        return $module->path.DIRECTORY_SEPARATOR.'Models'.DIRECTORY_SEPARATOR."{$modelName}.php";
    }

    private function migrationConflict(ModuleDefinition $module, string $tableName): ?string
    {
        $directory = $module->path.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations';

        $pattern = '/^\d{4}_\d{2}_\d{2}_\d{6}_create_'.preg_quote($tableName, '/').'_table\.php$/';
        $creating = array_filter($this->files->isDirectory($directory) ? $this->files->files($directory) : [], fn (SplFileInfo $file): bool => preg_match($pattern, $file->getFilename()) === 1);
        $first = array_shift($creating);

        return $first === null ? null : "Migration [{$first->getFilename()}] already creates table [{$tableName}] in module [{$module->name}].";
    }

    private function factoryConflict(ModuleDefinition $module, string $modelName): ?string
    {
        return $this->files->isFile($this->factories->factoryPath($module, "{$modelName}Factory")) ? "Factory [{$modelName}Factory] already exists in module [{$module->name}]." : null;
    }

    private function seederConflict(ModuleDefinition $module, string $modelName): ?string
    {
        return $this->files->isFile($this->seeders->seederPath($module, "{$modelName}Seeder")) ? "Seeder [{$modelName}Seeder] already exists in module [{$module->name}]." : null;
    }
}
