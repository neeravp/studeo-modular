<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Studeo\Modular\Exceptions\ModuleLoadException;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Makes boot-time module failures visible without breaking the application:
 * a warning before each artisan command, a report for HTTP requests, and a
 * thrown exception for HTTP requests in debug mode only.
 */
final class ReportModuleLoadFailuresAction
{
    public function __construct(
        private readonly Dispatcher $events,
        private readonly ExceptionHandler $handler,
        private readonly Repository $config,
    ) {}

    /**
     * @param  array<array-key, ModuleLoadException>  $failures
     */
    public function execute(array $failures, bool $runningInConsole): void
    {
        match (true) {
            $failures === [] => null,
            $runningInConsole => $this->warnBeforeEachCommand($failures),
            default => $this->reportForHttp(array_values($failures)),
        };
    }

    /**
     * @param  array<array-key, ModuleLoadException>  $failures
     */
    private function warnBeforeEachCommand(array $failures): void
    {
        $this->events->listen(CommandStarting::class, function (CommandStarting $event) use ($failures): void {
            $this->displayWarnings($this->warningStream($event->output), $failures);
        });
    }

    private function warningStream(OutputInterface $output): OutputInterface
    {
        return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }

    /**
     * @param  array<array-key, ModuleLoadException>  $failures
     */
    private function displayWarnings(OutputInterface $output, array $failures): void
    {
        if ($output->isQuiet()) {
            return;
        }

        foreach ($failures as $failure) {
            $output->writeln('<comment>'.$failure->getMessage().'</comment>');
        }
    }

    /**
     * @param  array<int, ModuleLoadException>  $failures
     */
    private function reportForHttp(array $failures): void
    {
        $thrown = $this->isDebug() ? array_shift($failures) : null;

        foreach ($failures as $failure) {
            $this->handler->report($failure);
        }

        if ($thrown !== null) {
            throw $thrown;
        }
    }

    private function isDebug(): bool
    {
        return (bool) $this->config->get('app.debug');
    }
}
