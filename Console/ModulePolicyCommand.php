<?php

namespace Studeo\Modular\Console;

use Illuminate\Support\Str;
use Studeo\Modular\Console\Actions\CreatePolicyAction;
use Studeo\Modular\Console\Actions\DiscoverModelsAction;
use Studeo\Modular\Console\Prompts\ModulePicker;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Support\Schema\ModuleModels;
use Throwable;

use function Laravel\Prompts\select;

class ModulePolicyCommand extends ModuleCommand
{
    protected $signature = 'module:policy
        {name? : The name of the policy class}
        {--module= : The name of the module}
        {--model= : The name of the model to bind to the policy}';

    protected $description = 'Create a new policy class in a module';

    public function handle(
        CreatePolicyAction $creator,
        DiscoverModelsAction $modelsDiscoverer,
        ModulePicker $picker,
        ModuleModels $models,
    ): int {
        try {
            $name = $this->resolveNameArgument(
                label: 'What is the name of the policy?',
                placeholder: 'e.g. FlightPolicy',
                example: 'FlightPolicy',
            );

            $module = $picker->resolve($this, $this->scalarOption('module'));
            $model = $this->resolveModelInput($name, $module, $modelsDiscoverer, $models);

            $path = $creator->execute($module, $name, $model);
        } catch (Throwable $exception) {
            return $this->reportFailure($exception);
        }

        $policyName = Str::beforeLast(basename($path), '.php');

        $this->displayCreated('Policy', $policyName, $module->name);

        return self::SUCCESS;
    }

    private function resolveModelInput(
        string $name,
        ModuleDefinition $module,
        DiscoverModelsAction $modelsDiscoverer,
        ModuleModels $models,
    ): ?string {
        if ($this->stringOption('model') !== null) {
            return $models->resolve($module, (string) $this->stringOption('model'));
        }

        $available = $this->canPrompt() ? array_keys($modelsDiscoverer->forModule($module)) : [];

        return $available === [] ? null : $this->askModel($name, $available);
    }

    /**
     * @param  array<int, string>  $models
     */
    private function askModel(string $name, array $models): ?string
    {
        $guessedModel = Str::beforeLast(Str::studly($name), 'Policy');

        $selected = select(
            label: 'Which model should this policy bind to?',
            options: ['None', ...$models],
            default: in_array($guessedModel, $models, true) ? $guessedModel : 'None',
        );

        return $selected === 'None' ? null : (string) $selected;
    }
}
