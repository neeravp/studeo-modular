<?php

namespace Studeo\Modular\Console;

use Studeo\Modular\Console\Actions\CreatePivotAction;
use Studeo\Modular\Console\Actions\DiscoverModelsAction;
use Studeo\Modular\Console\Prompts\ModulePicker;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\PivotGenerationOptions;
use Studeo\Modular\DataObjects\PivotGenerationResult;
use Studeo\Modular\Exceptions\NoTerminalException;
use Throwable;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\text;

class ModulePivotCommand extends ModuleCommand
{
    protected $signature = 'module:pivot
        {model1? : The first model or compound pivot name (e.g. FlightPassenger)}
        {model2? : The second model}
        {--module= : The name of the module}
        {--table= : Explicit pivot table name}
        {--no-migration : Do not create a pivot migration}
        {--morph : Generate a MorphPivot model and polymorphic migration}';

    protected $description = 'Create a new Eloquent pivot model and migration in a module';

    public function handle(
        CreatePivotAction $creator,
        DiscoverModelsAction $modelsDiscoverer,
        ModulePicker $picker,
    ): int {
        try {
            $module = $picker->resolve($this, $this->scalarOption('module'));

            [$model1, $model2] = $this->resolveModelInputs($modelsDiscoverer, $module);

            $options = new PivotGenerationOptions(
                withMigration: ! (bool) $this->option('no-migration'),
                table: filled($this->stringOption('table')) ? (string) $this->stringOption('table') : null,
                morph: (bool) $this->option('morph'),
            );

            $result = $creator->execute($module, $model1, $model2, $options);
        } catch (Throwable $exception) {
            return $this->reportFailure($exception);
        }

        $this->displaySuccess($result, $module->name);

        return self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function resolveModelInputs(
        DiscoverModelsAction $modelsDiscoverer,
        ModuleDefinition $module,
    ): array {
        $model1 = $this->stringArgument('model1');
        $model2 = $this->stringArgument('model2');

        return $model1 !== null
            ? [$model1, $model2]
            : $this->promptForModels($modelsDiscoverer, $module);
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function promptForModels(
        DiscoverModelsAction $modelsDiscoverer,
        ModuleDefinition $module,
    ): array {
        if (! $this->canPrompt()) {
            throw new NoTerminalException('the [model1] argument', "{$this->getName()} Flight Passenger --module=Blog");
        }

        $availableModels = $modelsDiscoverer->forModule($module);

        if (count($availableModels) >= 2) {
            $selected = multiselect(
                label: 'Select the 2 models for this pivot relationship:',
                options: array_keys($availableModels),
                hint: 'Use the space bar to select exactly two models, then press Enter.',
                validate: fn (array $values) => count($values) === 2
                    ? null
                    : 'Please select exactly two models.',
            );

            return array_values($selected);
        }

        $model1 = text(
            label: 'What is the first model?',
            placeholder: 'e.g. Flight',
            required: true,
        );

        $model2 = text(
            label: 'What is the second model?',
            placeholder: 'e.g. Passenger',
            required: true,
        );

        return [$model1, $model2];
    }

    private function displaySuccess(PivotGenerationResult $result, string $moduleName): void
    {
        $this->info("Pivot [{$result->modelName}] created successfully in module [{$moduleName}].");

        if ($result->migrationPath !== null) {
            $this->line("<comment>Migration:</comment> {$result->migrationPath}");
        }
    }
}
