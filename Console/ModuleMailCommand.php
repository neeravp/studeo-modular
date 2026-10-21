<?php

namespace Studeo\Modular\Console;

use Studeo\Modular\Console\Actions\CreateMailAction;
use Studeo\Modular\Console\Prompts\ModulePicker;
use Studeo\Modular\DataObjects\MailGenerationOptions;
use Studeo\Modular\DataObjects\MailGenerationResult;
use Throwable;

class ModuleMailCommand extends ModuleCommand
{
    protected $signature = 'module:mail
        {name? : The name of the mailable}
        {--module= : The name of the module}
        {--markdown= : Create a new Markdown template for the mailable}
        {--view= : Create a new Blade template for the mailable}
        {--queued : Mark the mailable as queued (implements ShouldQueue)}';

    protected $description = 'Create a new email class in a module';

    #[\Override]
    protected function configureDefaults(): void
    {
        $this->getDefinition()->getOption('markdown')->setDefault(false);
        $this->getDefinition()->getOption('view')->setDefault(false);
    }

    public function handle(CreateMailAction $creator, ModulePicker $picker): int
    {
        try {
            $module = $picker->resolve($this, $this->scalarOption('module'));

            $name = $this->resolveMailName();
            $options = new MailGenerationOptions(
                markdown: $this->resolveTemplateOption('markdown'),
                view: $this->resolveTemplateOption('view'),
                queued: (bool) $this->option('queued'),
            );

            $result = $creator->execute($module, $name, $options);
        } catch (Throwable $exception) {
            return $this->reportFailure($exception);
        }

        $mailName = basename($result->mailPath, '.php');
        $this->displaySuccess($mailName, $result, $module->name);

        return self::SUCCESS;
    }

    private function resolveMailName(): string
    {
        return $this->resolveNameArgument(
            label: 'What should the mailable be named?',
            placeholder: 'e.g. OrderShipped, OrderShippedMail',
            example: 'OrderShipped',
        );
    }

    private function displaySuccess(string $mailName, MailGenerationResult $result, string $moduleName): void
    {
        $this->info("Mail [{$mailName}] created successfully in module [{$moduleName}].");
        $this->line("<comment>Path:</comment> {$result->mailPath}");

        if ($result->viewPath !== null) {
            $this->line("<comment>View:</comment> {$result->viewPath}");
        }
    }
}
