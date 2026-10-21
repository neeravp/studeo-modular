<?php

namespace Studeo\Modular\Console;

use Studeo\Modular\Console\Actions\CreateJobAction;
use Studeo\Modular\Console\Prompts\ModulePicker;
use Studeo\Modular\DataObjects\JobGenerationOptions;
use Throwable;

class ModuleJobCommand extends ModuleCommand
{
    protected $signature = 'module:job
        {name? : The name of the job}
        {--module= : The name of the module}
        {--sync : Indicates that the job should be synchronous}
        {--batched : Indicates that the job should be batchable}';

    protected $description = 'Create a new job class in a module';

    public function handle(CreateJobAction $creator, ModulePicker $picker): int
    {
        try {
            $module = $picker->resolve($this, $this->scalarOption('module'));

            $name = $this->resolveJobName();
            $options = new JobGenerationOptions(
                sync: (bool) $this->option('sync'),
                batched: (bool) $this->option('batched'),
            );

            $path = $creator->execute($module, $name, $options);
        } catch (Throwable $exception) {
            return $this->reportFailure($exception);
        }

        $jobName = basename($path, '.php');
        $this->displayCreated('Job', $jobName, $module->name, $path);

        return self::SUCCESS;
    }

    private function resolveJobName(): string
    {
        return $this->resolveNameArgument(
            label: 'What should the job be named?',
            placeholder: 'e.g. ProcessPodcast, SendInvoiceReport',
            example: 'ProcessPodcast',
        );
    }
}
