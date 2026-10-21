<?php

namespace Studeo\Modular\Console\Concerns;

trait ChecksPrompting
{
    /**
     * Whether a prompt can be answered: the same rule Laravel applies before it shows one, so a
     * command run with `-n`, or with no terminal on stdin, reports the missing value instead.
     */
    public function canPrompt(): bool
    {
        return $this->input->isInteractive() && ((defined('STDIN') && stream_isatty(STDIN)) || $this->laravel->runningUnitTests());
    }
}
