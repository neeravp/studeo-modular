<?php

namespace Studeo\Modular\Console;

use Studeo\Modular\Console\Actions\CreateMiddlewareAction;
use Studeo\Modular\Console\Prompts\ModulePicker;
use Throwable;

class ModuleMiddlewareCommand extends ModuleCommand
{
    protected $signature = 'module:middleware
        {name? : The name of the middleware}
        {--module= : The name of the module}';

    protected $description = 'Create a new HTTP middleware class in a module';

    public function handle(CreateMiddlewareAction $creator, ModulePicker $picker): int
    {
        try {
            $module = $picker->resolve($this, $this->scalarOption('module'));
            $name = $this->resolveMiddlewareName();

            $path = $creator->execute($module, $name);
        } catch (Throwable $exception) {
            return $this->reportFailure($exception);
        }

        $middlewareName = basename($path, '.php');
        $this->displayCreated('Middleware', $middlewareName, $module->name, $path);

        return self::SUCCESS;
    }

    private function resolveMiddlewareName(): string
    {
        return $this->resolveNameArgument(
            label: 'What should the middleware be named?',
            placeholder: 'e.g. EnsureAccountIsActive, VerifyTokenMiddleware',
            example: 'EnsureAccountIsActive',
        );
    }
}
