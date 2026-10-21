<?php

namespace Studeo\Modular\Console;

use Illuminate\Support\Str;
use Studeo\Modular\Console\Actions\CreateActionControllerAction;
use Studeo\Modular\Console\Actions\CreateControllerAction;
use Studeo\Modular\Console\Actions\ResolveControllerOptionsAction;
use Studeo\Modular\Console\Prompts\ModulePicker;
use Studeo\Modular\DataObjects\ControllerGenerationOptions;
use Studeo\Modular\DataObjects\ControllerGenerationResult;
use Throwable;

class ModuleControllerCommand extends ModuleCommand
{
    protected $signature = 'module:controller
        {name? : The name of the controller class; Controller is appended when missing, and with --action it defaults to the Action\'s name}
        {--module= : The name of the module}
        {--r|resource : Generate a resource controller}
        {--api : Generate an API resource controller}
        {--standard : Generate a plain controller with no methods}
        {--inertia : Render Inertia pages from the invokable controller, or from the index, create, show and edit of --resource}
        {--model= : With --resource or --api, a model of the module that show, edit, update and destroy bind}
        {--action= : The Action the invokable controller calls, e.g. CreatePost; it must exist unless --schema is given}
        {--schema= : With --action, a schema (@name, a file path, or inline) that generates the Action and its Input when missing}
        {--context=* : With --action, where a #[Context] field of the Input comes from, as field=actor}
        {--url= : With --action, the URL of the Route::post line to print, e.g. /posts}';

    protected $description = 'Create a new controller class in a module';

    public function handle(
        CreateControllerAction $creator,
        CreateActionControllerAction $actionCreator,
        ResolveControllerOptionsAction $optionsResolver,
        ModulePicker $picker,
    ): int {
        try {
            $options = $optionsResolver->execute($this->options());
            $result = $options->action === null
                ? $this->plain($creator, $options, $picker)
                : $actionCreator->execute($picker->resolve($this, $this->scalarOption('module')), $this->stringArgument('name'), $options);
        } catch (Throwable $exception) {
            return $this->reportFailure($exception);
        }

        $this->report($result);

        return self::SUCCESS;
    }

    private function plain(CreateControllerAction $creator, ControllerGenerationOptions $options, ModulePicker $picker): ControllerGenerationResult
    {
        $name = $this->stringArgument('name') ?? $this->promptForMissingName(
            label: 'What is the name of the controller?',
            placeholder: 'e.g. FlightController',
            example: 'FlightController',
        );

        $module = $picker->resolve($this, $this->scalarOption('module'));

        return new ControllerGenerationResult($creator->execute($module, $name, $options), $module->name);
    }

    private function report(ControllerGenerationResult $result): void
    {
        $this->info('Controller ['.Str::beforeLast(basename($result->path), '.php')."] created successfully in module [{$result->module}].");

        foreach ($result->created as $file) {
            $this->line("<comment>Created</comment> {$file}");
        }

        foreach ($result->reused as $file) {
            $this->line("<comment>Reusing</comment> the existing {$file}");
        }

        foreach ($result->notices as $notice) {
            $this->line($notice);
        }

        if ($result->route !== null) {
            $this->line("<comment>Route:</comment> {$result->route}");
        }
    }
}
