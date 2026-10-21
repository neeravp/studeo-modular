<?php

namespace Studeo\Modular\Console;

use RuntimeException;
use Studeo\Modular\Console\Actions\CreateSchemaFileAction;
use Studeo\Modular\Console\Prompts\ModulePicker;
use Throwable;

class ModuleSchemaCommand extends ModuleCommand
{
    protected $signature = 'module:schema
        {name? : The model name, e.g. Post; the file is schema/{table}.yaml}
        {--module= : The name of the module}
        {--fields= : Fields in the compact form, e.g. "title:string,slug:string:unique,author:belongsTo(User)"}';

    protected $description = 'Scaffold a schema file for a model in a module';

    public function handle(CreateSchemaFileAction $creator, ModulePicker $picker): int
    {
        try {
            $module = $picker->resolve($this, $this->scalarOption('module'));

            $name = $this->resolveCheckedName(
                label: 'What is the model name?',
                placeholder: 'e.g. Post',
                check: $this->ensureModelNameIsNotEmpty(...),
                missingMessage: 'The model name is required when the command is not interactive, e.g. Post.',
            );

            $path = $creator->execute($module, $name, $this->stringOption('fields'));
        } catch (Throwable $exception) {
            return $this->reportFailure($exception);
        }

        $this->displayCreated('Schema', basename($path, '.yaml'), $module->name, $path);

        return self::SUCCESS;
    }

    private function ensureModelNameIsNotEmpty(string $name): bool
    {
        if (trim($name) === '') {
            throw new RuntimeException('The model name cannot be empty.');
        }

        return true;
    }
}
