<?php

namespace Studeo\Modular\Console;

use Studeo\Modular\Console\Actions\CreateModelAction;
use Studeo\Modular\Console\Actions\ResolveModelNameAction;
use Studeo\Modular\Console\Prompts\ModulePicker;
use Studeo\Modular\DataObjects\ModelGenerationOptions;
use Studeo\Modular\DataObjects\ModelGenerationResult;
use Throwable;

class ModuleModelCommand extends ModuleCommand
{
    protected $signature = 'module:model
        {name? : The name of the model class}
        {--module= : The name of the module}
        {--no-migration : Do not create a migration file}
        {--no-factory : Do not create a factory file}
        {--no-seeder : Do not create a seeder file}
        {--schema= : A schema (@name, a file path, or inline) to take the attributes from}';

    protected $description = 'Create a new Eloquent model class in a module';

    public function handle(CreateModelAction $creator, ResolveModelNameAction $names, ModulePicker $picker): int
    {
        try {
            $name = $names->execute($this->resolveCheckedName(
                label: 'What is the name of the model?',
                placeholder: 'e.g. Flight',
                check: $names->execute(...),
                missingMessage: 'The model name is required when the command is not interactive, e.g. module:model Post --module=Blog.',
            ));

            $module = $picker->resolve($this, $this->scalarOption('module'));

            $options = new ModelGenerationOptions(
                withMigration: ! $this->option('no-migration'),
                withFactory: ! $this->option('no-factory'),
                withSeeder: ! $this->option('no-seeder'),
                schemaPath: $this->stringOption('schema'),
            );

            $result = $creator->execute($module, $name, $options);
        } catch (Throwable $exception) {
            return $this->reportFailure($exception);
        }

        $this->displaySuccess($name, $module->name, $result);

        return self::SUCCESS;
    }

    private function displaySuccess(string $name, string $moduleName, ModelGenerationResult $result): void
    {
        $this->info("Model [{$name}] created successfully in module [{$moduleName}].");

        $paths = ['Model' => $result->modelPath, 'Migration' => $result->migrationPath, 'Factory' => $result->factoryPath, 'Seeder' => $result->seederPath];

        foreach (array_filter($paths) as $label => $path) {
            $this->line("<comment>{$label}:</comment> {$path}");
        }
    }
}
