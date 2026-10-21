<?php

namespace Studeo\Modular\Console;

use Studeo\Modular\Console\Actions\CreateRuleAction;
use Studeo\Modular\Console\Prompts\ModulePicker;
use Studeo\Modular\DataObjects\RuleGenerationOptions;
use Throwable;

class ModuleRuleCommand extends ModuleCommand
{
    protected $signature = 'module:rule
        {name? : The name of the validation rule}
        {--module= : The name of the module}
        {--implicit : Generate an implicit validation rule}';

    protected $description = 'Create a new validation rule class in a module';

    public function handle(CreateRuleAction $creator, ModulePicker $picker): int
    {
        try {
            $module = $picker->resolve($this, $this->scalarOption('module'));

            $name = $this->resolveRuleName();
            $options = new RuleGenerationOptions(
                implicit: (bool) $this->option('implicit'),
            );

            $path = $creator->execute($module, $name, $options);
        } catch (Throwable $exception) {
            return $this->reportFailure($exception);
        }

        $ruleName = basename($path, '.php');
        $this->displayCreated('Validation rule', $ruleName, $module->name, $path);

        return self::SUCCESS;
    }

    private function resolveRuleName(): string
    {
        return $this->resolveNameArgument(
            label: 'What should the validation rule be named?',
            placeholder: 'e.g. Uppercase, ValidVatNumber',
            example: 'Uppercase',
        );
    }
}
