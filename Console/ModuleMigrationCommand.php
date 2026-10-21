<?php

namespace Studeo\Modular\Console;

use Studeo\Modular\Console\Actions\CreateMigrationAction;
use Studeo\Modular\Console\Prompts\ModulePicker;
use Studeo\Modular\DataObjects\MigrationGenerationOptions;
use Studeo\Modular\DataObjects\MigrationGenerationResult;
use Throwable;

class ModuleMigrationCommand extends ModuleCommand
{
    protected $signature = 'module:migration
        {name? : The name of the migration}
        {--module= : The name of the module}
        {--create= : The table to be created}
        {--table= : The table to be updated}
        {--blank : Create an empty migration}
        {--pivot : Create a pivot table migration}
        {--morph : Create a polymorphic pivot table migration}';

    protected $description = 'Create a new migration file in a module';

    public function handle(CreateMigrationAction $creator, ModulePicker $picker): int
    {
        try {
            $module = $picker->resolve($this, $this->scalarOption('module'));

            $name = $this->resolveMigrationName();
            $options = $this->resolveOptions();

            $result = $creator->execute($module, $name, $options);
        } catch (Throwable $exception) {
            return $this->reportFailure($exception);
        }

        $this->displaySuccess($result, $module->name);

        return self::SUCCESS;
    }

    private function resolveMigrationName(): string
    {
        return $this->resolveNameArgument(
            label: 'What should the migration be named?',
            placeholder: 'e.g. create_flights_table, add_status_to_orders_table',
            example: 'create_flights_table',
        );
    }

    private function resolveOptions(): MigrationGenerationOptions
    {
        $create = filled($this->stringOption('create')) ? (string) $this->stringOption('create') : null;
        $table = filled($this->stringOption('table')) ? (string) $this->stringOption('table') : null;
        $blank = (bool) $this->option('blank');
        $pivot = (bool) $this->option('pivot');
        $morph = (bool) $this->option('morph');

        return new MigrationGenerationOptions(
            create: $create,
            table: $table,
            blank: $blank,
            pivot: $pivot,
            morph: $morph,
        );
    }

    private function displaySuccess(MigrationGenerationResult $result, string $moduleName): void
    {
        $this->info("Migration [{$result->filename}] created successfully in module [{$moduleName}].");
        $this->line("<comment>Path:</comment> {$result->path}");
    }
}
