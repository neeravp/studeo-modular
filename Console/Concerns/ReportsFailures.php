<?php

namespace Studeo\Modular\Console\Concerns;

use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

trait ReportsFailures
{
    /**
     * A command-level failure: the message always, the stack trace as well under -v, both on the
     * same stream. The exit code is the same either way.
     */
    protected function reportFailure(Throwable $exception): int
    {
        $this->writeFailure($exception->getMessage(), $exception->getTraceAsString());

        return $this->failureExitCode();
    }

    /**
     * Where a failure is said. A command whose standard output is data overrides this.
     */
    protected function writeFailure(string $message, string $trace): void
    {
        $this->error($message);
        $this->line($trace, null, OutputInterface::VERBOSITY_VERBOSE);
    }

    /**
     * What a reported failure exits with. A command whose exit 1 means something else overrides it.
     */
    protected function failureExitCode(): int
    {
        return self::FAILURE;
    }
}
