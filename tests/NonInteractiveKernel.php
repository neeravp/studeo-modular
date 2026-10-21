<?php

namespace Studeo\Modular\Tests;

use Orchestra\Testbench\Foundation\Console\Kernel;
use Throwable;

/**
 * The testbench console kernel, except that a command run through `Artisan::call()` takes no
 * input from the terminal. `$this->artisan()` hands the kernel a mocked output buffer and
 * answers prompts through expectations, so it keeps its interactive input.
 *
 * @codeCoverageIgnore
 */
final class NonInteractiveKernel extends Kernel
{
    protected $commands = [];

    public function call($command, array $parameters = [], $outputBuffer = null)
    {
        if ($outputBuffer === null) {
            $parameters['--no-interaction'] = true;
        }

        return parent::call($command, $parameters, $outputBuffer);
    }

    protected function reportException(Throwable $e)
    {
        throw $e;
    }
}
