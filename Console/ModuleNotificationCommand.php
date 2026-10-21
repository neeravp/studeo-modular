<?php

namespace Studeo\Modular\Console;

use Studeo\Modular\Console\Actions\CreateNotificationAction;
use Studeo\Modular\Console\Prompts\ModulePicker;
use Studeo\Modular\DataObjects\NotificationGenerationOptions;
use Studeo\Modular\DataObjects\NotificationGenerationResult;
use Throwable;

class ModuleNotificationCommand extends ModuleCommand
{
    protected $signature = 'module:notification
        {name? : The name of the notification}
        {--module= : The name of the module}
        {--markdown= : Create a new Markdown template for the notification}
        {--queued : Mark the notification as queued (implements ShouldQueue)}';

    protected $description = 'Create a new notification class in a module';

    #[\Override]
    protected function configureDefaults(): void
    {
        $this->getDefinition()->getOption('markdown')->setDefault(false);
    }

    public function handle(CreateNotificationAction $creator, ModulePicker $picker): int
    {
        try {
            $module = $picker->resolve($this, $this->scalarOption('module'));

            $name = $this->resolveNotificationName();
            $options = new NotificationGenerationOptions(
                markdown: $this->resolveTemplateOption('markdown'),
                queued: (bool) $this->option('queued'),
            );

            $result = $creator->execute($module, $name, $options);
        } catch (Throwable $exception) {
            return $this->reportFailure($exception);
        }

        $notificationName = basename($result->notificationPath, '.php');
        $this->displaySuccess($notificationName, $result, $module->name);

        return self::SUCCESS;
    }

    private function resolveNotificationName(): string
    {
        return $this->resolveNameArgument(
            label: 'What should the notification be named?',
            placeholder: 'e.g. InvoicePaid, InvoicePaidNotification',
            example: 'InvoicePaid',
        );
    }

    private function displaySuccess(string $notificationName, NotificationGenerationResult $result, string $moduleName): void
    {
        $this->info("Notification [{$notificationName}] created successfully in module [{$moduleName}].");
        $this->line("<comment>Path:</comment> {$result->notificationPath}");

        if ($result->viewPath !== null) {
            $this->line("<comment>Markdown View:</comment> {$result->viewPath}");
        }
    }
}
